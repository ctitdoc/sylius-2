<?php

declare(strict_types=1);

namespace Tests\Alma\Sylius\Integration\Security;

use Alma\Sylius\Resolver\AlmaPaymentMethodResolver;
use Doctrine\ORM\EntityManagerInterface;
use ParagonIE\Halite\KeyFactory;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The plugin as it is (plugin v1, Payum path) keeps its own cipher of the two API keys, and this
 * test holds what the Merchant relies on: the keys never reach the database in clear, and they
 * read back in clear ([[merchant-configuration]], brick "key encryption"). It runs with Sylius's
 * encryption key present, as a merchant's shop has one — and does not look at how Sylius behaves
 * with it: that question was answered once, in the documents (audit point 3 of sylius-plugin.md),
 * and the plugin v2 of cycle 3 will replace this cipher by Sylius's native encryption, with a
 * migration of the stored values; this test will then evolve with that change.
 *
 * @implementation [[sylius-plugin-commons.md#key-encryption]]
 */
final class ApiKeysStoredCipheredTest extends KernelTestCase
{
    private const CLEAR_TEST_KEY = 'sk_test_characterisation_0123456789';

    private const METHOD_CODE = 'alma_characterisation';

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        // Sylius's key, as a merchant's shop has one (`sylius:payment:generate-key`).
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
        self::assertFileExists($keyPath, 'Sylius encryption key present, as in a merchant shop');

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

    public function testTheApiKeysAreStoredCipheredAndReadBackInClear(): void
    {
        $methodFactory = self::getContainer()->get('sylius.factory.payment_method');
        self::assertInstanceOf(FactoryInterface::class, $methodFactory);
        $method = $methodFactory->createNew();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $method->setCode(self::METHOD_CODE);

        $gatewayConfigFactory = self::getContainer()->get('sylius.factory.gateway_config');
        self::assertInstanceOf(FactoryInterface::class, $gatewayConfigFactory);
        $gatewayConfig = $gatewayConfigFactory->createNew();
        self::assertInstanceOf(GatewayConfigInterface::class, $gatewayConfig);
        $gatewayConfig->setGatewayName(self::METHOD_CODE);
        $gatewayConfig->setFactoryName(AlmaPaymentMethodResolver::GATEWAY_FACTORY_NAME);
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
        self::assertStringNotContainsString(self::CLEAR_TEST_KEY, $raw, 'the clear API key never reaches the database');
        $stored = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($stored);
        self::assertIsString($stored['api_key_test'] ?? null);
        self::assertStringStartsWith('v1:', $stored['api_key_test'], "the plugin's cipher, version 1, protects the stored key");

        // What the Merchant's configuration looks like once read back.
        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository($gatewayConfig::class)->find($id);
        self::assertInstanceOf(GatewayConfigInterface::class, $reloaded);
        $readBack = $reloaded->getConfig();
        self::assertSame(self::CLEAR_TEST_KEY, $readBack['api_key_test'] ?? null, 'the API key reads back in clear');
        self::assertSame('test', $readBack['api_mode'] ?? null, 'the rest of the configuration reads back as stored');
    }

    private function deleteTheMethod(): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM sylius_payment_method WHERE code = :code',
            ['code' => self::METHOD_CODE],
        );
    }
}
