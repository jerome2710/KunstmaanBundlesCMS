<?php

namespace Kunstmaan\GeneratorBundle\Command;

use Kunstmaan\GeneratorBundle\Generator\AdminTestsGenerator;
use Kunstmaan\GeneratorBundle\Helper\Sf4AppBundle;
use Sensio\Bundle\GeneratorBundle\Command\GeneratorCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @internal
 */
class GenerateAdminTestsCommand extends GeneratorCommand
{
    /**
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDefinition(
                [
                    new InputOption('namespace', '', InputOption::VALUE_REQUIRED, 'The namespace to generate the tests in'),
                ]
            )
            ->setDescription('Generates the tests used to test the admin created by the default-site generator')
            ->setHelp(<<<'EOT'
The <info>kuma:generate:admin-test</info> command generates tests to test the admin generated by the default-site generator

<info>php bin/console kuma:generate:admin-tests --namespace=Namespace/NamedBundle</info>

EOT
            )
            ->setName('kuma:generate:admin-tests');
    }

    /**
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $questionHelper = $this->getQuestionHelper();
        $questionHelper->writeSection($output, 'Admin Tests Generation');

        $bundle = new Sf4AppBundle($this->getContainer()->getParameter('kernel.project_dir'));
        $generator = $this->getGenerator($this->getApplication()->getKernel()->getBundle('KunstmaanGeneratorBundle'));
        $generator->generate($bundle, $output);

        return 0;
    }

    protected function createGenerator()
    {
        return new AdminTestsGenerator($this->getContainer(), new Filesystem(), '/admintests');
    }
}
