<?php

namespace Kunstmaan\GeneratorBundle\Generator;

use Doctrine\Inflector\InflectorFactory;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\EntityGenerator;
use Doctrine\ORM\Tools\EntityRepositoryGenerator;
use Doctrine\Persistence\ManagerRegistry;
use Kunstmaan\GeneratorBundle\Helper\CommandAssistant;
use Kunstmaan\GeneratorBundle\Helper\GeneratorUtils;
use Sensio\Bundle\GeneratorBundle\Generator\Generator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Twig\Environment;
use Twig\Lexer;
use Twig\Loader\FilesystemLoader;

/**
 * Contains all common generator logic.
 */
class KunstmaanGenerator extends Generator
{
    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var ManagerRegistry
     */
    protected $registry;

    /**
     * @var string
     */
    protected $skeletonDir;

    /**
     * @var CommandAssistant
     */
    protected $assistant;

    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @param Filesystem         $filesystem  The filesystem
     * @param ManagerRegistry    $registry    The registry
     * @param string             $skeletonDir The directory of the skeleton
     * @param CommandAssistant   $assistant   The command assistant
     * @param ContainerInterface $container   The container
     */
    public function __construct(
        Filesystem $filesystem,
        ManagerRegistry $registry,
        $skeletonDir,
        CommandAssistant $assistant,
        ?ContainerInterface $container = null,
    ) {
        $this->filesystem = $filesystem;
        $this->registry = $registry;
        $this->skeletonDir = GeneratorUtils::getFullSkeletonPath($skeletonDir);
        $this->assistant = $assistant;
        $this->container = $container;

        $this->setSkeletonDirs([$this->skeletonDir, GeneratorUtils::getFullSkeletonPath('/common')]);
    }

    /**
     * Check that the keyword is a reserved word for the database system.
     *
     * @param string $keyword
     *
     * @return bool
     */
    public function isReservedKeyword($keyword)
    {
        return $this->registry->getConnection()->getDatabasePlatform()->getReservedKeywordsList()->isKeyword($keyword);
    }

    /**
     * Generate the entity PHP code.
     *
     * @param string      $name
     * @param array       $fields
     * @param string      $namePrefix
     * @param string      $dbPrefix
     * @param string|null $extendClass
     * @param bool        $withRepository
     *
     * @return array
     *
     * @throws \RuntimeException
     */
    protected function generateEntity(
        BundleInterface $bundle,
        $name,
        $fields,
        $namePrefix,
        $dbPrefix,
        $extendClass = null,
        $withRepository = false,
    ) {
        // configure the bundle (needed if the bundle does not contain any Entities yet)
        $config = $this->registry->getManager(null)->getConfiguration();
        $config->setEntityNamespaces(
            array_merge(
                [$bundle->getName() => $bundle->getNamespace() . '\\Entity' . ($namePrefix ? '\\' . $namePrefix : '')],
                $config->getEntityNamespaces()
            )
        );

        $entityClass = $this->registry->getAliasNamespace($bundle->getName()) . ($namePrefix ? '\\' . $namePrefix : '') . '\\' . $name;
        $entityPath = $bundle->getPath() . '/Entity/' . ($namePrefix ? $namePrefix . '/' : '') . str_replace('\\', '/', $name) . '.php';
        if (file_exists($entityPath)) {
            throw new \RuntimeException(sprintf('Entity "%s" already exists.', $entityClass));
        }

        $class = new ClassMetadataInfo($entityClass, new UnderscoreNamingStrategy());
        if ($withRepository) {
            $repositoryClass = preg_replace('/\\\\Entity\\\\/', '\\Repository\\', $entityClass, 1) . 'Repository';
            $class->customRepositoryClassName = $repositoryClass;
            $this->getSymfony4RepositoryGenerator()->writeEntityRepositoryClass($entityClass, $repositoryClass, $bundle->getPath());
        }

        foreach ($fields as $fieldSet) {
            foreach ($fieldSet as $fieldArray) {
                foreach ($fieldArray as $field) {
                    if (array_key_exists('joinColumn', $field)) {
                        $class->mapManyToOne($field);
                    } elseif (array_key_exists('joinTable', $field)) {
                        $class->mapManyToMany($field);
                    } else {
                        $class->mapField($field);
                    }
                }
            }
        }

        $inflector = InflectorFactory::create()->build();
        $class->setPrimaryTable(
            [
                'name' => strtolower($dbPrefix) . $inflector->tableize($inflector->pluralize($name)),
            ]
        );
        $entityCode = $this->getEntityGenerator($extendClass)->generateEntityClass($class);

        return [$entityCode, $entityPath];
    }

    /**
     * Get a Doctrine EntityGenerator instance.
     *
     * @param string|null $classToExtend
     *
     * @return EntityGenerator
     */
    protected function getEntityGenerator($classToExtend = null)
    {
        $entityGenerator = new EntityGenerator();
        if (!is_null($classToExtend)) {
            $entityGenerator->setClassToExtend($classToExtend);
        }
        $entityGenerator->setGenerateAnnotations(true);
        $entityGenerator->setGenerateStubMethods(true);
        $entityGenerator->setRegenerateEntityIfExists(false);
        $entityGenerator->setUpdateEntityIfExists(true);
        $entityGenerator->setNumSpaces(4);
        $entityGenerator->setAnnotationPrefix('ORM\\');

        return $entityGenerator;
    }

    /**
     * Generate the entity admin type.
     *
     * @param string $extendClass
     */
    protected function generateEntityAdminType(
        $bundle,
        $entityName,
        $entityPrefix,
        array $fields,
        $extendClass = '\Symfony\Component\Form\AbstractType',
    ) {
        $className = $entityName . 'AdminType';
        $savePath = $bundle->getPath() . '/Form/' . $entityPrefix . '/' . $className . '.php';
        $name = str_replace(
            '\\',
            '_',
            strtolower($bundle->getNamespace())
        ) . '_' . strtolower($entityName) . 'type';

        $params = [
            'className' => $className,
            'name' => $name,
            'namespace' => $bundle->getNamespace(),
            'entity' => '\\' . $bundle->getNamespace() . '\Entity\\' . $entityPrefix . '\\' . $entityName,
            'fields' => $fields,
            'entity_prefix' => $entityPrefix,
            'extend_class' => $extendClass,
        ];
        $this->renderFile('/Form/EntityAdminType.php', $savePath, $params);
    }

    /**
     * Install the default page templates.
     *
     * @param BundleInterface $bundle
     */
    protected function installDefaultPageTemplates($bundle)
    {
        // Configuration templates
        $dirPath = $this->container->getParameter('kernel.project_dir') . '/config/kunstmaancms/pagetemplates/';
        $skeletonDir = sprintf('%s/Resources/config/pagetemplates/', GeneratorUtils::getFullSkeletonPath('/common'));

        // Only copy templates over when the folder does not exist yet...
        if (!$this->filesystem->exists($dirPath)) {
            $files = [
                'default-one-column.yml',
                'default-two-column-left.yml',
                'default-two-column-right.yml',
                'default-three-column.yml',
            ];
            foreach ($files as $file) {
                $this->filesystem->copy($skeletonDir . $file, $dirPath . $file, false);
                GeneratorUtils::replace('~~~BUNDLE~~~', $bundle->getName(), $dirPath . $file);
            }
        }

        // Twig templates
        $dirPath = $this->getTemplateDir($bundle) . '/Pages/Common/';

        $skeletonDir = sprintf('%s/Resources/views/Pages/Common/', GeneratorUtils::getFullSkeletonPath('/common'));

        if (!$this->filesystem->exists($dirPath)) {
            $files = [
                'one-column-pagetemplate.html.twig',
                'two-column-left-pagetemplate.html.twig',
                'two-column-right-pagetemplate.html.twig',
                'three-column-pagetemplate.html.twig',
            ];
            foreach ($files as $file) {
                $this->filesystem->copy($skeletonDir . $file, $dirPath . $file, false);
            }
            $this->filesystem->copy($skeletonDir . 'view.html.twig', $dirPath . 'view.html.twig', false);
        }

        $contents = file_get_contents($dirPath . 'view.html.twig');

        $twigFile = "{% extends 'Layout/layout.html.twig' %}\n";

        if (strpos($contents, '{% extends ') === false) {
            GeneratorUtils::prepend(
                $twigFile,
                $dirPath . 'view.html.twig'
            );
        }
    }

    /**
     * Install the default pagepart configuration.
     *
     * @param BundleInterface $bundle
     */
    protected function installDefaultPagePartConfiguration($bundle)
    {
        // Pagepart configuration
        $dirPath = $this->container->getParameter('kernel.project_dir') . '/config/kunstmaancms/pageparts/';
        $skeletonDir = sprintf('%s/Resources/config/pageparts/', GeneratorUtils::getFullSkeletonPath('/common'));

        // Only copy when folder does not exist yet
        if (!$this->filesystem->exists($dirPath)) {
            $files = ['footer.yml', 'main.yml', 'left-sidebar.yml', 'right-sidebar.yml'];
            foreach ($files as $file) {
                $this->filesystem->copy($skeletonDir . $file, $dirPath . $file, false);
            }
        }
    }

    /**
     * Render all files in the source directory and copy them to the target directory.
     *
     * @param string $sourceDir  The source directory where we need to look in
     * @param string $targetDir  The target directory where we need to copy the files too
     * @param array  $parameters The parameters that will be passed to the templates
     * @param bool   $override   Whether to override an existing file or not
     * @param bool   $recursive  Whether to render all files recursively or not
     */
    public function renderFiles($sourceDir, $targetDir, array $parameters, $override = false, $recursive = true)
    {
        // Make sure the source -and target dir contain a trailing slash
        $sourceDir = rtrim($sourceDir, '/') . '/';
        $targetDir = rtrim($targetDir, '/') . '/';

        $this->setSkeletonDirs([$sourceDir]);

        $finder = new Finder();
        $finder->files()->in($sourceDir);
        if (!$recursive) {
            $finder->depth('== 0');
        }

        // Get all files in the source directory
        foreach ($finder as $file) {
            $name = $file->getRelativePathname();

            // Check that we are allowed to overwrite the file if it already exists
            if (!is_file($targetDir . $name) || $override === true) {
                $fileParts = explode('.', $name);
                if (end($fileParts) === 'twig') {
                    $this->renderTwigFile($name, $targetDir . $name, $parameters, $sourceDir);
                } else {
                    $this->renderFile($name, $targetDir . $name, $parameters);
                }
            }
        }
    }

    /**
     * Render all files in the source directory and copy them to the target directory.
     *
     * @param string      $sourceDir      The source directory where we need to look in
     * @param string      $targetDir      The target directory where we need to copy the files too
     * @param string      $filename       The name of the file that needs to be rendered
     * @param array       $parameters     The parameters that will be passed to the templates
     * @param bool        $override       Whether to override an existing file or not
     * @param string|null $targetFilename The name of the target file (if null, then use $filename)
     */
    public function renderSingleFile($sourceDir, $targetDir, $filename, array $parameters, $override = false, $targetFilename = null)
    {
        // Make sure the source -and target dir contain a trailing slash
        $sourceDir = rtrim($sourceDir, '/') . '/';
        $targetDir = rtrim($targetDir, '/') . '/';
        if (is_null($targetFilename)) {
            $targetFilename = $filename;
        }

        $this->setSkeletonDirs([$sourceDir]);

        if (is_file($sourceDir . $filename)) {
            // Check that we are allowed the overwrite the file if it already exists
            if (!is_file($targetDir . $targetFilename) || $override === true) {
                $fileParts = explode('.', $filename);
                if (end($fileParts) === 'twig') {
                    $this->renderTwigFile($filename, $targetDir . $targetFilename, $parameters, $sourceDir);
                } else {
                    $this->renderFile($filename, $targetDir . $targetFilename, $parameters);
                }
            }
        }
    }

    /**
     * Render a file and make it executable.
     *
     * @param string $sourceDir  The source directory where we need to look in
     * @param string $targetDir  The target directory where we need to copy the files too
     * @param string $filename   The name of the file that needs to be rendered
     * @param array  $parameters The parameters that will be passed to the templates
     * @param bool   $override   Whether to override an existing file or not
     * @param int    $mode       The mode
     */
    public function renderExecutableFile($sourceDir, $targetDir, $filename, array $parameters, $override = false, $mode = 0774)
    {
        $this->renderSingleFile($sourceDir, $targetDir, $filename, $parameters, $override);

        $targetDir = rtrim($targetDir, '/') . '/';
        $targetFile = $targetDir . $filename;
        $this->filesystem->chmod($targetFile, $mode);
    }

    /**
     * Copy all files in the source directory to the target directory.
     *
     * @param string $sourceDir The source directory where we need to look in
     * @param string $targetDir The target directory where we need to copy the files too
     * @param bool   $override  Whether to override an existing file or not
     */
    public function copyFiles($sourceDir, $targetDir, $override = false)
    {
        // Make sure the source -and target dir contain a trailing slash
        $sourceDir = rtrim($sourceDir, '/') . '/';
        $targetDir = rtrim($targetDir, '/') . '/';

        $this->filesystem->mirror($sourceDir, $targetDir, null, ['override' => $override]);
    }

    /**
     * Remove a directory from the filesystem.
     *
     * @param string $targetDir
     */
    public function removeDirectory($targetDir)
    {
        // Make sure the target dir contain a trailing slash
        $targetDir = rtrim($targetDir, '/') . '/';

        $this->filesystem->remove($targetDir);
    }

    /**
     * Remove a file from the filesystem.
     *
     * @param string $file
     */
    public function removeFile($file)
    {
        $this->filesystem->remove($file);
    }

    /**
     * Render a twig file with custom twig tags.
     *
     * @param string $template
     * @param string $sourceDir
     *
     * @return string
     */
    public function renderTwig($template, array $parameters, $sourceDir)
    {
        $twig = new Environment(
            new FilesystemLoader([$sourceDir]), [
                'debug' => true,
                'cache' => false,
                'strict_variables' => true,
                'autoescape' => false,
            ]
        );

        // Ruby erb template syntax
        $lexer = new Lexer(
            $twig, [
                'tag_comment' => ['<%#', '%>'],
                'tag_block' => ['<%', '%>'],
                'tag_variable' => ['<%=', '%>'],
            ]
        );

        $twig->setLexer($lexer);

        return $twig->render($template, $parameters);
    }

    /**
     * Render a twig file, and save it to disk.
     *
     * @param string $template
     * @param string $target
     * @param string $sourceDir
     *
     * @return int
     */
    public function renderTwigFile($template, $target, array $parameters, $sourceDir)
    {
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }

        return file_put_contents($target, $this->renderTwig($template, $parameters, $sourceDir));
    }

    /**
     * @return \Doctrine\ORM\Tools\EntityRepositoryGenerator
     */
    protected function getRepositoryGenerator()
    {
        return new EntityRepositoryGenerator();
    }

    /**
     * @return \Kunstmaan\GeneratorBundle\Generator\Symfony4EntityRepositoryGenerator
     */
    protected function getSymfony4RepositoryGenerator()
    {
        return new \Kunstmaan\GeneratorBundle\Generator\Symfony4EntityRepositoryGenerator();
    }

    /**
     * @internal
     */
    protected function getTemplateDir(BundleInterface $bundle)
    {
        return $this->container->getParameter('kernel.project_dir') . '/templates';
    }

    /**
     * @internal
     */
    protected function getAssetsDir(BundleInterface $bundle)
    {
        return $this->container->getParameter('kernel.project_dir') . '/assets';
    }
}
