<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\ComposerConstrainer\Business\Finder;

use ArrayObject;
use Generated\Shared\Transfer\UsedModuleCollectionTransfer;
use Generated\Shared\Transfer\UsedModuleTransfer;
use ReflectionMethod;
use SprykerSdk\Zed\ComposerConstrainer\Business\SprykerReflector\SprykerClassReflector;
use SprykerSdk\Zed\ComposerConstrainer\Business\SprykerReflector\SprykerReflectionHelper;
use SprykerSdk\Zed\ComposerConstrainer\Business\SprykerReflector\SprykerXmlReflector;
use SprykerSdk\Zed\ComposerConstrainer\Business\SprykerReflector\SprykerYamlReflector;
use SprykerSdk\Zed\ComposerConstrainer\ComposerConstrainerConfig;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class StrictFinder implements FinderInterface
{
    /**
     * @var \SprykerSdk\Zed\ComposerConstrainer\ComposerConstrainerConfig
     */
    protected $config;

    /**
     * @param \SprykerSdk\Zed\ComposerConstrainer\ComposerConstrainerConfig $config
     */
    public function __construct(ComposerConstrainerConfig $config)
    {
        $this->config = $config;
    }

    /**
     * @return \Generated\Shared\Transfer\UsedModuleCollectionTransfer
     */
    public function find(): UsedModuleCollectionTransfer
    {
        $usedModuleCollectionTransfer = new UsedModuleCollectionTransfer();

        if (!is_dir($this->config->getSourceDirectory())) {
            return $usedModuleCollectionTransfer;
        }

        $usedModules = [];
        foreach ($this->createFinder() as $splFileInfo) {
            switch ($splFileInfo->getExtension()) {
                case "php":
                    $sprykerClassReflector = new SprykerClassReflector($this->config, $splFileInfo);
                    $usedModules = $this->checkPhpPublicApiCustomization($usedModules, $sprykerClassReflector);
                    $usedModules = $this->checkPhpNonPublicApiCustomization($usedModules, $sprykerClassReflector);
                    $usedModules = $this->checkPhpDependencies($usedModules, $sprykerClassReflector);
                    break;
                case "xml":
                    $sprykerXmlReflector = new SprykerXmlReflector($this->config, $splFileInfo);
                    $usedModules = $this->addXmlUsedModules($usedModules, $sprykerXmlReflector);
                    break;
                case "yaml":
                    $sprykerYamlReflector = new SprykerYamlReflector($this->config, $splFileInfo);
                    $usedModules = $this->addYamlUsedModules($usedModules, $sprykerYamlReflector);
                    break;
                case "twig":
                    $usedModules = $this->addTwigUsedModules($usedModules, $splFileInfo);
                    break;
            }
        }

        return $usedModuleCollectionTransfer->setUsedModules(new ArrayObject($usedModules));
    }

    /**
     * @return \Symfony\Component\Finder\Finder|\Symfony\Component\Finder\SplFileInfo[]
     */
    protected function createFinder(): Finder
    {
        $finder = new Finder();
        $finder
            ->files()
            ->in('src/Pyz/') // TODO needs to come from config
            ->exclude(['Generated', 'Orm'])
            ->name([ '*.php', '*transfer.xml', '*schema.xml', '*.twig', '*navigation.xml', '*validation.yaml']);

        return $finder;
    }

    /**
     * Specification:
     * - Validation changes are customiziation
     * - Validation changes CAN NOT be transformed to pluggable so no impact on line count
     *
     * @param \Generated\Shared\Transfer\UsedModuleTransfer[] $usedModules
     * @param \SprykerSdk\Zed\ComposerConstrainer\Business\SprykerReflector\SprykerYamlReflector $sprykerYamlReflector
     *
     * @return \Generated\Shared\Transfer\UsedModuleTransfer[]
     */
    protected function addYamlUsedModules(array $usedModules, SprykerYamlReflector $sprykerYamlReflector): array
    {
        $usedModuleTransfer = $this->setrieveUsedModule(
            $usedModules,
            $sprykerYamlReflector->getPackageName(),
            $sprykerYamlReflector->getOrganisation(),
            $sprykerYamlReflector->getModuleName()
        );

        $usedModuleTransfer
            ->setIsCustomized(true)
            ->addConstraintReason('Customized: validation.yaml defined');

        return $usedModules;
    }

    /**
     * Specification:
     * - Transfer definitions are NOT considered customization or configuration or dependency
     * - Navigation changes are customiziation
     * - Navigation changes CAN NOT be transformed to pluggable so no impact on line count
     * - Schema changes are dependency toward the module's major version
     * - Schema changes CAN NOT be transformed to pluggable so no impact on line count
     *
     * @param \Generated\Shared\Transfer\UsedModuleTransfer[] $usedModules
     * @param \SprykerSdk\Zed\ComposerConstrainer\Business\SprykerReflector\SprykerXmlReflector $sprykerXmlReflector
     *
     * @return \Generated\Shared\Transfer\UsedModuleTransfer[]
     */
    protected function addXmlUsedModules(array $usedModules, SprykerXmlReflector $sprykerXmlReflector): array
    {
        if ($sprykerXmlReflector->getIsTransfer()) {
            return $usedModules;
        }

        $usedModuleTransfer = $this->setrieveUsedModule(
            $usedModules,
            $sprykerXmlReflector->getPackageName(),
            $sprykerXmlReflector->getOrganisation(),
            $sprykerXmlReflector->getModuleName()
        );

        if ($sprykerXmlReflector->getIsNavigation()) {
            $usedModuleTransfer
                ->setIsCustomized(true)
                ->addConstraintReason('Customized: navigation.xml defined');

            return $usedModules;
        }

        $usedModuleTransfer->addConstraintReason('Dependency: schema.xml');

        return $usedModules;
    }

    /**
     * @param \Generated\Shared\Transfer\UsedModuleTransfer[] $usedModules
     * @param \Symfony\Component\Finder\SplFileInfo $splFileInfo
     *
     * @return \Generated\Shared\Transfer\UsedModuleTransfer[]
     */
    protected function addTwigUsedModules(array $usedModules, SplFileInfo $splFileInfo): array
    {
        // TODO

        return $usedModules;
    }

    /**
     * Specification
     * - Only core class extensions are evaluted for customisation
     * - Class extension is customization
     * - All customization lines are added to line count except Factory
     *
     * @param \Generated\Shared\Transfer\UsedModuleTransfer[] $usedModules
     * @param \SprykerSdk\Zed\ComposerConstrainer\Business\SprykerReflector\SprykerClassReflector $sprykerClassReflector
     *
     * @return \Generated\Shared\Transfer\UsedModuleTransfer[]
     */
    protected function checkPhpNonPublicApiCustomization(array $usedModules, SprykerClassReflector $sprykerClassReflector): array
    {
        // TODO calling protected/private entity in an extended external API class is customization
        // TODO adding constants & properties on top of method checks

        if ($sprykerClassReflector->getIsPublicApi()) {
            return $usedModules;
        }

        if (!$sprykerClassReflector->getIsParentCore()) {
            return $usedModules;
        }

        $usedModuleTransfer = $this->setrieveUsedModule(
            $usedModules,
            $sprykerClassReflector->getParentPackageName(),
            $sprykerClassReflector->getParentOrganisation(),
            $sprykerClassReflector->getParentModuleName()
        );

        $usedModuleTransfer->setIsCustomized(true);

        foreach ($sprykerClassReflector->getNewMethods() as $method) {
            if ($sprykerClassReflector->getIsFactory()) {
                $usedModuleTransfer->addConstraintReason('Customized: ' . $sprykerClassReflector->getClassName() . '::' . $method->getShortName() . '()');

                continue;
            }

            $customizedLineCount = $this->getMethodBodySize($method, $sprykerClassReflector->getIsInterface());
            $usedModuleTransfer->setCustomizedLineCount($usedModuleTransfer->getCustomizedLineCount() + $customizedLineCount);
            $usedModuleTransfer->addConstraintReason('Customized: ' . $sprykerClassReflector->getClassName() . '::' . $method->getShortName() . '()' . ' - ' . $customizedLineCount);
        }

        return $usedModules;
    }

    /**
     * Specification
     * - ?? New Facade with interface only?
     * - Only core class extensions are evaluted for customisation. ?? New Facade with interface only?
     * - Config and dependency provider class extension: public entity overriding is configuration
     * - Config and dependency provider class extension: entity addition or call to a protected/private entity is customization
     * - External API class extension: public entity overriding is depenency toward the module's major version
     * - External API class extension: entity addition or call to a protected/private entity is customization
     * - Class extension: class extension is customization
     * - For trail version all customization lines are added to line count except Factory changes and adding public API public entity
     *
     * @param \Generated\Shared\Transfer\UsedModuleTransfer[] $usedModules
     * @param \SprykerSdk\Zed\ComposerConstrainer\Business\SprykerReflector\SprykerClassReflector $sprykerClassReflector
     *
     * @return \Generated\Shared\Transfer\UsedModuleTransfer[]
     */
    protected function checkPhpPublicApiCustomization(array $usedModules, SprykerClassReflector $sprykerClassReflector): array
    {
        if (!$sprykerClassReflector->getIsPublicApi()) {
            return $usedModules;
        }

        if (!$sprykerClassReflector->getIsParentCore()) {
            return $usedModules;
        }

        $inheritedMethodNames = [];
        $parentMethods = $sprykerClassReflector->getParentMethods();
        foreach ($parentMethods as $method) {
            $inheritedMethodNames[$method->getShortName()] = $method->getShortName();
        }

        foreach ($sprykerClassReflector->getNewMethods() as $method) {
            $isPublic = ($method->getModifiers() & ReflectionMethod::IS_PUBLIC) > 0;
            $isProtected = ($method->getModifiers() & (ReflectionMethod::IS_PROTECTED + ReflectionMethod::IS_PRIVATE)) > 0;
            $isPublicMethodOverriden = $isPublic && array_key_exists($method->getShortName(), $inheritedMethodNames);

            $usedModuleTransfer = $this->setrieveUsedModule(
                $usedModules,
                $sprykerClassReflector->getParentPackageName(),
                $sprykerClassReflector->getParentOrganisation(),
                $sprykerClassReflector->getParentModuleName()
            );

            if ($isPublicMethodOverriden) {
                if ($sprykerClassReflector->getIsConfiguration()) {
                    $usedModuleTransfer->setIsConfigured(true);
                    $usedModuleTransfer->addConstraintReason('Configured: public API method overriden by ' . $sprykerClassReflector->getClassName() . '::' . $method->getShortName() . '()');
                } else {
                    $usedModuleTransfer->addConstraintReason('Dependency: public API method overriden by ' . $sprykerClassReflector->getClassName() . '::' . $method->getShortName() . '()');
                }

                continue;
            }

            $usedModuleTransfer->setIsCustomized(true);

            if ($isPublic) {
                $usedModuleTransfer->addConstraintReason('Customized: ' . $sprykerClassReflector->getClassName() . '::' . $method->getShortName() . '()');
                continue;
            }

            $customizedLineCount = $this->getMethodBodySize($method, $sprykerClassReflector->getIsInterface());
            $usedModuleTransfer->setCustomizedLineCount($usedModuleTransfer->getCustomizedLineCount() + $customizedLineCount);
            $usedModuleTransfer->addConstraintReason('Customized: ' . $sprykerClassReflector->getClassName() . '::' . $method->getShortName() . '()' . ' - ' . $customizedLineCount);
        }

        return $usedModules;
    }

    protected function getMethodBodySize(ReflectionMethod $method, bool $isClassInterface): int
    {
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine() ?? $method->getStartLine();
        $compensation = ($method->isAbstract() || $isClassInterface) ? 0 : 2;

        return $endLine - $startLine - $compensation;
    }

    /**
     * Specification
     * - Another core module: using another core module's external API is dependency toward that module's major version
     * - Another core module: using another core module's non-external API is prohibited
     *
     * @param \Generated\Shared\Transfer\UsedModuleTransfer[] $usedModules
     * @param \SprykerSdk\Zed\ComposerConstrainer\Business\SprykerReflector\SprykerClassReflector $sprykerClassReflector
     *
     * @return \Generated\Shared\Transfer\UsedModuleTransfer[]
     */
    protected function checkPhpDependencies(array $usedModules, SprykerClassReflector $sprykerClassReflector): array
    {
        // TODO Another core module: forcing module dependency with "@module" is dependency toward that module's major version

        foreach ($sprykerClassReflector->getUsedCorePackageNames() as $usedCorePackageName) {
            [$usedOrganisation, $usedModuleName] = SprykerReflectionHelper::packageNameToNamespace($usedCorePackageName);
            $usedModuleTransfer = $this->setrieveUsedModule(
                $usedModules,
                $usedCorePackageName,
                $usedOrganisation,
                $usedModuleName,
            );

            $usedModuleTransfer->addConstraintReason('Dependency: incoming from ' . $sprykerClassReflector->getFileName());
        }

        return $usedModules;
    }

    /**
     * Specification:
     * - Sets and/or retrieves expected element
     * - Instantiates missing searched element in the provided array by reference.
     *
     * @param \Generated\Shared\Transfer\UsedModuleTransfer[] &$usedModules
     * @param string $packageName
     * @param string $organisation
     * @param string $moduleName
     *
     * @return \Generated\Shared\Transfer\UsedModuleTransfer
     */
    protected function setrieveUsedModule(array &$usedModules, string $packageName, string $organisation, string $moduleName): UsedModuleTransfer
    {
        if (!isset($usedModules[$packageName])) {
            $usedModules[$packageName] = (new UsedModuleTransfer())
                ->setIsConfigured(false)
                ->setIsCustomized(false)
                ->setCustomizedLineCount(0)
                ->setModule($moduleName)
                ->setOrganization($organisation)
                ->setPackageName($packageName)
                ->setConstraintReasons([]);
        }

        return $usedModules[$packageName];
    }

    /**
     * @param string $packageName
     * @param string $organisation
     * @param string $module
     *
     * @return \Generated\Shared\Transfer\UsedModuleTransfer
     */
    protected function initUsedModuleTransfer(string $packageName, string $organisation, string $module): UsedModuleTransfer
    {
        return (new UsedModuleTransfer())
            ->setIsConfigured(false)
            ->setIsCustomized(false)
            ->setCustomizedLineCount(0)
            ->setModule($module)
            ->setOrganization($organisation)
            ->setPackageName($packageName)
            ->setConstraintReasons([]);
    }
}
