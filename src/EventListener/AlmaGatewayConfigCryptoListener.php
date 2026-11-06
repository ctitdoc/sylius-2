<?php

declare(strict_types=1);

namespace Alma\Sylius\EventListener;

use Alma\Sylius\Resolver\AlmaPaymentMethodResolver;
use Alma\Sylius\Security\ApiKeyCipher;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Sylius\Component\Payment\Model\GatewayConfigInterface;

/**
 * Encrypts Alma API keys inside the `config` JSON of an Alma PaymentMethod's
 * gatewayConfig.
 *
 * Targets the Sylius {@see GatewayConfigInterface} entity, filters on
 * `factoryName === 'alma'`, and only touches the `api_key_live` / `api_key_test`
 * keys of the `config` array — every other key stays in cleartext (`api_mode`,
 * `merchant_id`, `fee_plans`, `fee_plan_overrides`, `product_widget_enabled`).
 *
 * The cipher uses {@see ApiKeyCipher} keyed by Symfony's `kernel.secret`,
 * preserving the encryption invariant documented in CLAUDE.md.
 */
final class AlmaGatewayConfigCryptoListener
{
    private const ENCRYPTED_KEYS = ['api_key_live', 'api_key_test'];

    public function __construct(private readonly ApiKeyCipher $cipher)
    {
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$this->isAlmaGatewayConfig($entity)) {
            return;
        }

        $this->applyToConfig($entity, fn (string $value): string => $this->cipher->isEncrypted($value)
            ? $this->cipher->decrypt($value)
            : $value);
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$this->isAlmaGatewayConfig($entity)) {
            return;
        }

        $this->applyToConfig($entity, fn (string $value): string => $this->cipher->isEncrypted($value)
            ? $value
            : $this->cipher->encrypt($value));
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$this->isAlmaGatewayConfig($entity)) {
            return;
        }

        if (!$args->hasChangedField('config')) {
            // GatewayConfig.config is a JSON column — Doctrine flags it as changed only on
            // wholesale replacement. The form rebuilds the array each save, so this path
            // covers normal admin edits. Defensive fallback through applyToConfig.
            $this->applyToConfig($entity, fn (string $value): string => $this->cipher->isEncrypted($value)
                ? $value
                : $this->cipher->encrypt($value));

            return;
        }

        $newConfig = $args->getNewValue('config');
        if (!\is_array($newConfig)) {
            return;
        }

        foreach (self::ENCRYPTED_KEYS as $key) {
            $value = $newConfig[$key] ?? null;
            if (!\is_string($value) || $value === '' || $this->cipher->isEncrypted($value)) {
                continue;
            }
            $newConfig[$key] = $this->cipher->encrypt($value);
        }

        $args->setNewValue('config', $newConfig);
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$this->isAlmaGatewayConfig($entity)) {
            return;
        }

        $this->applyToConfig($entity, fn (string $value): string => $this->cipher->isEncrypted($value)
            ? $this->cipher->decrypt($value)
            : $value);
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$this->isAlmaGatewayConfig($entity)) {
            return;
        }

        $this->applyToConfig($entity, fn (string $value): string => $this->cipher->isEncrypted($value)
            ? $this->cipher->decrypt($value)
            : $value);
    }

    private function isAlmaGatewayConfig(mixed $entity): bool
    {
        return $entity instanceof GatewayConfigInterface &&
            $entity->getFactoryName() === AlmaPaymentMethodResolver::GATEWAY_FACTORY_NAME;
    }

    /**
     * @param callable(string): string $transform
     */
    private function applyToConfig(GatewayConfigInterface $gatewayConfig, callable $transform): void
    {
        $config = $gatewayConfig->getConfig();
        $changed = false;

        foreach (self::ENCRYPTED_KEYS as $key) {
            $value = $config[$key] ?? null;
            if (!\is_string($value) || $value === '') {
                continue;
            }
            $new = $transform($value);
            if ($new !== $value) {
                $config[$key] = $new;
                $changed = true;
            }
        }

        if ($changed) {
            $gatewayConfig->setConfig($config);
        }
    }
}
