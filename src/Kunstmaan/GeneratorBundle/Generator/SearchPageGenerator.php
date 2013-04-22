<?php

namespace Kunstmaan\GeneratorBundle\Generator;

use Kunstmaan\GeneratorBundle\Helper\GeneratorUtils;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Generates a SearchPage using KunstmaanSearchBundle and KunstmaanNodeSearchBundle
 */
class SearchPageGenerator extends \Sensio\Bundle\GeneratorBundle\Generator\Generator
{

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var string
     */
    private $skeletonDir;

    /**
     * @param Filesystem $filesystem  The filesytem
     * @param string     $skeletonDir The skeleton directory

     */
    public function __construct(Filesystem $filesystem, $skeletonDir)
    {
        $this->filesystem = $filesystem;
        $this->skeletonDir = $skeletonDir . '/searchpage';
    }

    /**
     * @param Bundle          $bundle  The bundle
     * @param string          $prefix  The prefix
     * @param string          $rootDir The root directory
     * @param OutputInterface $output
     */
    public function generate(Bundle $bundle, $prefix, $rootDir, OutputInterface $output)
    {
        $parameters = array(
            'namespace'         => $bundle->getNamespace(),
            'bundle'            => $bundle,
            'prefix'            => $prefix
        );

        $this->generateEntities($bundle, $parameters, $output);
        $this->generateTemplates($bundle, $parameters, $rootDir, $output);
    }


    /**
     * @param Bundle          $bundle     The bundle
     * @param array           $parameters The template parameters
     * @param string          $rootDir    The root directory
     * @param OutputInterface $output
     */
    public function generateTemplates(Bundle $bundle, array $parameters, $rootDir, OutputInterface $output)
    {
        $dirPath = $bundle->getPath();
        $fullSkeletonDir = $this->skeletonDir . '/Resources/views';

        $this->filesystem->copy($fullSkeletonDir . '/Pages/Search/SearchPage/view.html.twig', $dirPath . '/Resources/views/Pages/Search/SearchPage/view.html.twig', true);
        GeneratorUtils::prepend("{% extends '" . $bundle->getName() .":Page:layout.html.twig' %}\n", $dirPath . '/Resources/views/Pages/Search/SearchPage/view.html.twig');

        $output->writeln('Generating Twig Templates : <info>OK</info>');

    }

    /**
     * @param Bundle          $bundle     The bundle
     * @param array           $parameters The template parameters
     * @param OutputInterface $output
     */
    public function generateEntities(Bundle $bundle, array $parameters, OutputInterface $output)
    {
        $dirPath = sprintf("%s/Entity/Pages/Search", $bundle->getPath());
        $fullSkeletonDir = sprintf("%s/Entity/Pages/Search", $this->skeletonDir);

        try {
            $this->generateSkeletonBasedClass($fullSkeletonDir, $dirPath, 'SearchPage', $parameters);
        } catch (\Exception $error) {
            $output->writeln($this->dialog->getHelperSet()->get('formatter')->formatBlock($error->getMessage(), 'error'));
        }

        $output->writeln('Generating entities : <info>OK</info>');
    }

    /**
     * @param string $fullSkeletonDir The full dir of the entity skeleton
     * @param string $dirPath         The full fir of where the entity should be created
     * @param string $className       The class name of the entity to create
     * @param array  $parameters      The template parameters
     *
     * @throws \RuntimeException
     */
    private function generateSkeletonBasedClass($fullSkeletonDir, $dirPath, $className, array $parameters)
    {
        $classPath = sprintf("%s/%s.php", $dirPath, $className);
        if (file_exists($classPath)) {
            throw new \RuntimeException(sprintf('Unable to generate the %s class as it already exists under the %s file', $className, $classPath));
        }
        $this->renderFile($fullSkeletonDir, $className . '.php', $classPath, $parameters);
    }

}
