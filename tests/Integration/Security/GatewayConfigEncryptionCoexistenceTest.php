<?php

declare(strict_types=1);

namespace Tests\Alma\Sylius\Integration\Security;

use Alma\Sylius\Resolver\AlmaPaymentMethodResolver;
use Doctrine\ORM\EntityManagerInterface;
use ParagonIE\Halite\KeyFactory;
use Sylius\Bundle\PaymentBundle\Checker\GatewayConfigEncryptionCheckerInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Encryption\EncrypterInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Characterisation test of audit point 3 (sylius-plugin.md): how the plugin's own cipher of the two
 * API keys (brick "key encryption", [[merchant-configuration]]) and Sylius 2's native encryption of
 * the whole gateway configuration coexist, when Sylius's key is present. Written on 6 November 2026
 * against what the code does; the assertions say what is observed, the messages say what it means.
 *
 * @implementation [[sylius-plugin-commons.md#key-encryption]]
 */
final class GatewayConfigEncryptionCoexistenceTest extends KernelTestCase
{
    private const CLEAR_TEST_KEY = 'sk_test_characterisation_0123456789';

    private const METHOD_CODE = 'alma_characterisation';

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        // Sylius's key: generated at the path the Test Application resolves, as `sylius:payment:generate-key` would.
        $keyPath = $_SERVER['SYLIUS_PAYMENT_ENCRYPTION_KEY_PATH'] ?? null;
        if (!\is_string($keyPath) || '' === $keyPath) {
            $projectDir = self::getContainer()->getParameter('kernel.project_dir');
            self::assertIsString($projectDir);
            $keyPath = $projectDir . '/config/encryption/key';
        }
        if (!is_file($keyPath)) {
            @mkdir(\dirname($keyPath), 0775, true);
            KeyFactory::save(KeyFactory::generateEncryptionKey(), $keyPath);
        }
        self::assertFileExists($keyPath, 'Sylius encryption key present for the test');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->deleteTheMethod();
    }

    protected function tearDown(): void
    {
        $this->deleteTheMethod();
        parent::tearDown();
    }

    public function testSyliusNativeEncryptionIsOffForEveryPayumGateway(): void
    {
        // The cause, read in Sylius 2.3.2: the PayumBundle decorates sylius.checker.gateway_config_encryption
        // (ConditionalGatewayConfigEncryptionCheckerDecoratorPass) with `&& !$gatewayConfig->getUsePayum()`.
        $gatewayConfig = $this->newGatewayConfig();
        $checker = self::getContainer()->get('sylius.checker.gateway_config_encryption');
        self::assertInstanceOf(GatewayConfigEncryptionCheckerInterface::class, $checker);

        self::assertTrue($gatewayConfig->getUsePayum(), 'a gateway config is a Payum one by default');
        self::assertFalse($checker->isEncryptionEnabled($gatewayConfig), 'Sylius does not encrypt a Payum gateway config');
        $gatewayConfig->setUsePayum(false);
        self::assertTrue($checker->isEncryptionEnabled($gatewayConfig), 'it would on the native path (cycle 3)');
    }

    /** The plugin as it is: Payum path, `use_payum` true — Sylius stays out, the plugin's cipher alone protects the keys. */
    public function testWhatIsStoredAndWhatIsReadBackOnThePayumPath(): void
    {
        [$pluginCipheredTheKey, $syliusEncrypted, $readBack, $observed] = $this->roundTrip(usePayum: true);

        self::assertSame(self::CLEAR_TEST_KEY, $readBack['api_key_test'] ?? null, "the API key reads back in clear ($observed)");
        self::assertSame('test', $readBack['api_mode'] ?? null, "the rest reads back in clear ($observed)");
        self::assertTrue($pluginCipheredTheKey, "the plugin's cipher applied to the stored key ($observed)");
        self::assertFalse($syliusEncrypted, "Sylius's native encryption did not apply ($observed)");
    }

    /** The plugin as cycle 3 will make it: native path, `use_payum` false — Sylius encrypts the whole configuration; the plugin's cipher works underneath. */
    public function testWhatIsStoredAndWhatIsReadBackOnTheNativePath(): void
    {
        [, $syliusEncrypted, $readBack, $observed] = $this->roundTrip(usePayum: false);

        self::assertTrue($syliusEncrypted, "Sylius's native encryption applied to the stored configuration ($observed)");
        self::assertSame(self::CLEAR_TEST_KEY, $readBack['api_key_test'] ?? null, "the API key still reads back in clear ($observed)");
        self::assertSame('test', $readBack['api_mode'] ?? null, "the rest reads back in clear ($observed)");
    }

    /**
     * @return array{bool, bool, array<string, mixed>, string} plugin cipher seen on the stored key, Sylius suffix seen, configuration read back, summary
     */
    private function roundTrip(bool $usePayum): array
    {
        $methodFactory = self::getContainer()->get('sylius.factory.payment_method');
        self::assertInstanceOf(FactoryInterface::class, $methodFactory);
        $method = $methodFactory->createNew();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $method->setCode(self::METHOD_CODE);
        $gatewayConfig = $this->newGatewayConfig();
        $gatewayConfig->setGatewayName(self::METHOD_CODE);
        $gatewayConfig->setUsePayum($usePayum);
        $gatewayConfig->setConfig([
            'api_mode' => 'test',
            'api_key_test' => self::CLEAR_TEST_KEY,
            'api_key_live' => '',
        ]);
        $method->setGatewayConfig($gatewayConfig);

        $this->entityManager->persist($method);
        $this->entityManager->flush();
        $id = $gatewayConfig->getId();

        // What the database holds.
        $raw = $this->entityManager->getConnection()->fetchOne(
            'SELECT config FROM sylius_gateway_config WHERE id = :id',
            ['id' => $id],
        );
        self::assertIsString($raw);
        $stored = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($stored);
        self::assertStringNotContainsString(self::CLEAR_TEST_KEY, $raw, 'the clear API key never reaches the database');

        $storedTestKey = \is_string($stored['api_key_test'] ?? null) ? $stored['api_key_test'] : '';
        $storedMode = \is_string($stored['api_mode'] ?? null) ? $stored['api_mode'] : '';
        $syliusEncrypted = str_ends_with($storedMode, EncrypterInterface::ENCRYPTION_SUFFIX) ||
            str_ends_with($storedTestKey, EncrypterInterface::ENCRYPTION_SUFFIX);
        $pluginCipheredTheKey = str_starts_with($storedTestKey, 'v1:');

        // What the merchant's configuration looks like once read back.
        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository($gatewayConfig::class)->find($id);
        self::assertInstanceOf(GatewayConfigInterface::class, $reloaded);
        $readBack = $reloaded->getConfig();
        $readBackKey = \is_string($readBack['api_key_test'] ?? null) ? $readBack['api_key_test'] : 'null';
        $readBackMode = \is_string($readBack['api_mode'] ?? null) ? $readBack['api_mode'] : 'null';

        $observed = sprintf(
            'use_payum=%s; stored: plugin v1 cipher on the key=%s, Sylius suffix=%s; read back api_mode=%s, api_key_test=%s…',
            var_export($usePayum, true),
            var_export($pluginCipheredTheKey, true),
            var_export($syliusEncrypted, true),
            $readBackMode,
            substr($readBackKey, 0, 8),
        );
        fwrite(\STDERR, "\n[characterisation] $observed\n");

        return [$pluginCipheredTheKey, $syliusEncrypted, $readBack, $observed];
    }

    private function newGatewayConfig(): GatewayConfigInterface
    {
        $factory = self::getContainer()->get('sylius.factory.gateway_config');
        self::assertInstanceOf(FactoryInterface::class, $factory);
        $gatewayConfig = $factory->createNew();
        self::assertInstanceOf(GatewayConfigInterface::class, $gatewayConfig);
        $gatewayConfig->setFactoryName(AlmaPaymentMethodResolver::GATEWAY_FACTORY_NAME);

        return $gatewayConfig;
    }

    private function deleteTheMethod(): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM sylius_payment_method WHERE code = :code',
            ['code' => self::METHOD_CODE],
        );
    }
}
