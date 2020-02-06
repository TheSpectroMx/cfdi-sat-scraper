<?php

declare(strict_types=1);

namespace PhpCfdi\CfdiSatScraper\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\FileCookieJar;
use PhpCfdi\CfdiSatScraper\Captcha\Resolvers\ConsoleCaptchaResolver;
use PhpCfdi\CfdiSatScraper\Captcha\Resolvers\DeCaptcherCaptchaResolver;
use PhpCfdi\CfdiSatScraper\Contracts\CaptchaResolverInterface;
use PhpCfdi\CfdiSatScraper\SATScraper;
use PhpCfdi\CfdiSatScraper\Tests\CaptchaLocalResolver\CaptchaLocalResolver;
use PhpCfdi\CfdiSatScraper\Tests\CaptchaLocalResolver\CaptchaLocalResolverClient;

class Factory
{
    /** @var string */
    private $repositoryPath;

    /** @var SATScraper|null */
    private $scraper;

    /** @var Repository|null */
    private $repository;

    public function __construct(string $repositoryPath)
    {
        $this->repositoryPath = $repositoryPath;
    }

    public static function createCaptchaResolver(): CaptchaResolverInterface
    {
        $resolver = strval(getenv('CAPTCHA_RESOLVER'));

        if ('console' === $resolver) {
            return new ConsoleCaptchaResolver();
        }

        if ('local' === $resolver) {
            return new CaptchaLocalResolver(
                new CaptchaLocalResolverClient(
                    strval(getenv('CAPTCHA_LOCAL_HOST')),
                    intval(getenv('CAPTCHA_LOCAL_PORT')),
                    intval(getenv('CAPTCHA_LOCAL_TIMEOUT')),
                    new Client()
                )
            );
        }

        if ('decaptcher' === $resolver) {
            return new DeCaptcherCaptchaResolver(
                new Client(),
                strval(getenv('DECAPTCHER_USERNAME')),
                strval(getenv('DECAPTCHER_PASSWORD'))
            );
        }

        throw new \RuntimeException('Unable to create resolver');
    }

    public function createSatScraper(): SATScraper
    {
        $rfc = strval(getenv('SAT_AUTH_RFC'));
        if ('' === $rfc) {
            throw new \RuntimeException('The is no environment variable SAT_AUTH_RFC');
        }

        $ciec = strval(getenv('SAT_AUTH_CIEC'));
        if ('' === $ciec) {
            throw new \RuntimeException('The is no environment variable SAT_AUTH_CIEC');
        }

        $cookieFile = __DIR__ . '/../../build/cookie-' . strtolower($rfc) . '.json';
        return new SATScraper($rfc, $ciec, new Client(), new FileCookieJar($cookieFile), static::createCaptchaResolver());
    }

    public function createRepository(string $filename): Repository
    {
        if (! file_exists($filename)) {
            throw new \RuntimeException(sprintf('Te repository file %s was not found', $filename));
        }
        return Repository::fromFile($filename);
    }

    public function getSatScraper(): SATScraper
    {
        if (null === $this->scraper) {
            $this->scraper = $this->createSatScraper();
        }
        return $this->scraper;
    }

    public function getRepository(): Repository
    {
        if (null === $this->repository) {
            $this->repository = $this->createRepository($this->repositoryPath);
        }
        return $this->repository;
    }
}
