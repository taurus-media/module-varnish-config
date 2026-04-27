<?php

declare(strict_types=1);

namespace Taurus\VarnishConfig\Console\Command;

use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Taurus\VarnishConfig\Model\VclManager;

class UpdateVclCommand extends Command
{
    /**
     * @param VclManager $vclManager
     * @param string|null $name
     */
    public function __construct(
        private readonly VclManager $vclManager,
        string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('taurus:varnish:update-vcl')
            ->setDescription('Download VCL template, generate the VCL file, and apply it to Varnish');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $output->writeln('<info>Downloading VCL template...</info>');
            $this->vclManager->run();
            $output->writeln('<info>VCL applied successfully.</info>');

            return Command::SUCCESS;
        } catch (LocalizedException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        } catch (\Exception $e) {
            $output->writeln('<error>Unexpected error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
