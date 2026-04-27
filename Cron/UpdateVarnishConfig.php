<?php

declare(strict_types=1);

namespace Taurus\VarnishConfig\Cron;

use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Taurus\VarnishConfig\Model\VclManager;

class UpdateVarnishConfig
{
    public function __construct(
        private readonly VclManager $vclManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $this->vclManager->run();
        } catch (LocalizedException $e) {
            $this->logger->error('Taurus_VarnishExtended: ' . $e->getMessage());
        } catch (\Exception $e) {
            $this->logger->critical('Taurus_VarnishExtended: unexpected error during VCL update', [
                'exception' => $e,
            ]);
        }
    }
}
