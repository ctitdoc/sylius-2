<?php

declare(strict_types=1);

namespace Alma\Sylius\DependencyInjection;

use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Sylius\Bundle\ResourceBundle\DependencyInjection\Extension\AbstractResourceExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

final class AlmaSyliusExtension extends AbstractResourceExtension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');
    }

    public function prepend(ContainerBuilder $container): void
    {
        $this->prependDoctrineMigrations($container);

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'AlmaSyliusPlugin' => [
                        'type' => 'attribute',
                        'dir' => 'src/Entity',
                        'prefix' => 'Alma\\Sylius\\Entity',
                        'is_bundle' => true,
                        'alias' => 'AlmaSylius',
                    ],
                ],
            ],
        ]);

        $container->prependExtensionConfig('framework', [
            'translator' => [
                'paths' => [\dirname(__DIR__, 2) . '/translations'],
            ],
        ]);

        $container->prependExtensionConfig('sylius_twig_hooks', [
            'hooks' => [
                'sylius_shop.shared.form.select_payment.payment.choice.alma.details' => [
                    'fee_plans' => [
                        'template' => '@AlmaSyliusPlugin/Shop/checkout/fee_plans.html.twig',
                        'priority' => 0,
                    ],
                ],
            ],
        ]);

        $hooksDir = \dirname(__DIR__, 2) . '/config/twig_hooks';
        if (is_dir($hooksDir)) {
            foreach (Finder::create()->files()->in($hooksDir)->name('*.yaml') as $file) {
                $parsed = Yaml::parseFile($file->getPathname());
                $hooks = \is_array($parsed) ? ($parsed['sylius_twig_hooks'] ?? null) : null;
                if (\is_array($hooks)) {
                    // The plugin's own hook files: their keys are hook names.
                    /** @var array<string, mixed> $hookConfig */
                    $hookConfig = $hooks;
                    $container->prependExtensionConfig('sylius_twig_hooks', $hookConfig);
                }
            }
        }
    }

    protected function getMigrationsNamespace(): string
    {
        return 'Alma\\Sylius\\Migrations';
    }

    protected function getMigrationsDirectory(): string
    {
        return '@AlmaSyliusPlugin/src/Migrations';
    }

    protected function getNamespacesOfMigrationsExecutedBefore(): array
    {
        return [
            'Sylius\Bundle\CoreBundle\Migrations',
        ];
    }
}
