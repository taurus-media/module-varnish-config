<?php

declare(strict_types=1);

namespace Taurus\VarnishConfig\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\PhpEnvironment\Request;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Shell;
use Magento\PageCache\Model\Config as PageCacheConfig;
use Magento\PageCache\Model\Varnish\VclGeneratorFactory;
use Psr\Log\LoggerInterface;

class VclManager
{
    private const XML_PATH_TEMPLATE_URL  = 'taurus_varnish_config/general/template_url';

    private const TEMPLATE_FILENAME = 'varnish_extended_template.vcl';
    private const OUTPUT_FILENAME   = 'varnish_extended.vcl';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Filesystem $filesystem
     * @param Curl $curl
     * @param Shell $shell
     * @param VclGeneratorFactory $vclGeneratorFactory
     * @param SerializerInterface $serializer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Filesystem $filesystem,
        private readonly Curl $curl,
        private readonly Shell $shell,
        private readonly VclGeneratorFactory $vclGeneratorFactory,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Main entry point: download template, generate VCL, save, and apply.
     *
     * @throws LocalizedException
     */
    public function run(): void
    {
        $templateUrl = trim((string) $this->scopeConfig->getValue(self::XML_PATH_TEMPLATE_URL));
        if ($templateUrl === '') {
            throw new LocalizedException(__('VCL template URL is not configured.'));
        }

        $templateContent = $this->downloadTemplate($templateUrl);
        $templatePath    = $this->saveTemplate($templateContent);

        try {
            $vcl      = $this->generateVcl($templatePath);
            $filePath = $this->saveVcl($vcl);
        } finally {
            $this->deleteTemplate($templatePath);
        }

        $this->applyVcl($filePath);
    }

    /**
     * Download the VCL template from the configured URL.
     */
    private function downloadTemplate(string $url): string
    {
        $this->logger->info('Taurus_VarnishExtended: downloading VCL template', ['url' => $url]);

        $this->curl->setTimeout(30);
        $this->curl->get($url);

        $status = $this->curl->getStatus();
        if ($status !== 200) {
            throw new LocalizedException(
                __('Failed to download VCL template from %1 (HTTP %2).', $url, $status)
            );
        }

        $body = $this->curl->getBody();
        if (empty($body)) {
            throw new LocalizedException(__('Downloaded VCL template is empty.'));
        }

        return $body;
    }

    /**
     * Persist the downloaded template to var/tmp and return its path relative to Magento root.
     * VclTemplateLocator::getTemplate() reads the $inputFile relative to the Magento root directory.
     */
    private function saveTemplate(string $content): string
    {
        $tmpDir = $this->filesystem->getDirectoryWrite(DirectoryList::TMP);
        $tmpDir->writeFile(self::TEMPLATE_FILENAME, $content);

        $rootDir = $this->filesystem->getDirectoryRead(DirectoryList::ROOT);
        return $rootDir->getRelativePath($tmpDir->getAbsolutePath(self::TEMPLATE_FILENAME));
    }

    /**
     * Use Magento's native VclGenerator to process the template placeholders.
     * Mirrors Config::getVclFile() but with a custom input template file.
     */
    private function generateVcl(string $templateRelativePath): string
    {
        $accessList = $this->scopeConfig->getValue(PageCacheConfig::XML_VARNISH_PAGECACHE_ACCESS_LIST);
        $rawDesignExceptions = $this->scopeConfig->getValue(
            PageCacheConfig::XML_VARNISH_PAGECACHE_DESIGN_THEME_REGEX,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        $vclGenerator = $this->vclGeneratorFactory->create([
            'backendHost'      => $this->scopeConfig->getValue(PageCacheConfig::XML_VARNISH_PAGECACHE_BACKEND_HOST),
            'backendPort'      => $this->scopeConfig->getValue(PageCacheConfig::XML_VARNISH_PAGECACHE_BACKEND_PORT),
            'accessList'       => $accessList ? explode(',', $accessList) : [],
            'gracePeriod'      => $this->scopeConfig->getValue(PageCacheConfig::XML_VARNISH_PAGECACHE_GRACE_PERIOD),
            'sslOffloadedHeader' => $this->scopeConfig->getValue(Request::XML_PATH_OFFLOADER_HEADER),
            'designExceptions' => $rawDesignExceptions
                ? $this->serializer->unserialize($rawDesignExceptions)
                : [],
        ]);

        return $vclGenerator->generateVcl(null, $templateRelativePath);
    }

    /**
     * Write the generated VCL to var/tmp and return the absolute file path.
     */
    private function saveVcl(string $vcl): string
    {
        $tmpDir = $this->filesystem->getDirectoryWrite(DirectoryList::TMP);
        $tmpDir->writeFile(self::OUTPUT_FILENAME, $vcl);

        $absolutePath = $tmpDir->getAbsolutePath(self::OUTPUT_FILENAME);
        $this->logger->info('Taurus_VarnishExtended: VCL saved', ['path' => $absolutePath]);

        return $absolutePath;
    }

    /**
     * Remove the temporary downloaded template file.
     */
    private function deleteTemplate(string $templateRelativePath): void
    {
        try {
            $rootDir = $this->filesystem->getDirectoryWrite(DirectoryList::ROOT);
            if ($rootDir->isExist($templateRelativePath)) {
                $rootDir->delete($templateRelativePath);
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                'Taurus_VarnishExtended: could not delete temp template file',
                ['path' => $templateRelativePath, 'error' => $e->getMessage()]
            );
        }
    }

    /**
     * Load and activate the new VCL via varnishadm.
     */
    private function applyVcl(string $filePath): void
    {
        $this->logger->info('Taurus_VarnishExtended: loading VCL', ['file' => $filePath]);

        $label = 'magento2_' . date('YmdHis');

        // vcl.load <label> <file>
        $this->shell->execute('varnishadm vcl.load %s %s 2>&1', [$label, $filePath]);

        // vcl.use <label>
        $this->shell->execute('varnishadm vcl.use %s 2>&1', [$label]);

        $this->logger->info('Taurus_VarnishExtended: VCL applied successfully', ['label' => $label]);
    }
}
