<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require 'vendor/autoload.php';

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

$sources = [
    'nemira'  => 'https://nemira.ro/carte/ebooks',
    'libris'  => 'https://www.libris.ro/ebooks',
];

$csvHeader = [
    'url',
    'title',
    'author',
    'image',
    'price',
    'old price',
    'external id'
];

$dataFolder = 'data/' . date('Y-m-d');
if (!is_dir($dataFolder)) {
    mkdir($dataFolder);
}

$client = new Client([
    'timeout' => 30,
    'verify'  => false
]);

foreach ($sources as $sourceName => $source) {
    $nextUrl = $source;
    $nextUrlSegments = parse_url($nextUrl);
    $baseUrl = $nextUrlSegments['scheme'] . '://' . $nextUrlSegments['host'];
    $pageCount = 0;
    $dataFile = $dataFolder . '/' . $sourceName . '.csv';

    if (file_exists($dataFile)) {
        echo "Today's data file for $sourceName already exists \n";
        continue;
    }

    echo "Scraping source: $sourceName \n";

    $fp = fopen($dataFile, 'w');
    fputcsv($fp, $csvHeader);

    while ($nextUrl) {
        $response = $client->get($nextUrl);
        $content = $response->getBody()->getContents();
        $crawler = new Crawler($content);

        switch ($sourceName) {
            case 'nemira':
                $productsSelector = '.products-grid li.item';
                $nextUrl = $crawler->filter('.pages li.next')->count() > 0 ? $crawler->filter('.pages li.next a')->attr('href') : false;
                break;
            case 'libris':
                $productsSelector = '.categ-prod-list li.categ-prod-item';
                $nextUrl = $crawler->filter('.pagination-list .pagination-item')->count() > 0 ? $source . $crawler->filter('.pagination-list .pagination-item a')->last()->attr('href') : false;
                break;
        }

        $crawler->filter($productsSelector)
            ->each(function (Crawler $node, $i) use ($sourceName, $fp, $baseUrl) {
                switch ($sourceName) {
                    case 'nemira':
                        $url     = $node->filter('.product-name a')->attr('href');
                        $image   = $node->filter('.product-item-img a img')->attr('src');
                        $title   = $node->filter('.product-name a')->text();
                        $author  = $node->filter('.author-name a')->count() > 0 ? $node->filter('.author-name a')->text() : '';
                        if ($node->filter('.regular-price .price')->count() > 0) {
                            $oldPrice = '';
                            $price    = $node->filter('.regular-price .price')->text();
                        } else {
                            $oldPrice = $node->filter('.old-price .price')->text();
                            $price    = $node->filter('.special-price .price')->text();
                        }
                        $idText = preg_match('#img\-([0-9]+)#i', $node->filter('.product-item-img a')->attr('id'), $idMatches);
                        $id = $idMatches[1];
                        break;

                    case 'libris':
                        $url     = $baseUrl . str_replace($baseUrl, '', $node->filter('.item-title a')->attr('href'));
                        $image   = $node->filter('.item-img')->attr('data-echo');
                        $title   = $node->filter('.item-title a h2')->text();
                        $author  = '';
                        if ($node->filter('.item-price .price-reduced .price-normal')->count() > 0) {
                            $oldPrice = $node->filter('.item-price .price-reduced .price-normal')->text();
                            $price    = $node->filter('.item-price .price-reduced')->innerText();
                        } else {
                            $oldPrice = '';
                            $price    = $node->filter('.item-price .price-reduced')->text();
                        }
                        $id = $node->filter('.item-title a')->attr('data-id');
                        break;
                }

                $csvLine = [
                    $url,
                    $title,
                    $author,
                    $image,
                    $price,
                    $oldPrice,
                    $id
                ];
                fputcsv($fp, $csvLine);

                //echo "$url == $image == $title == $author == $oldPrice == $price == $id \n";
            });

        if ($pageCount % 5 === 0) {
            sleep(3);
        }
        //if ($pageCount == 0) {
        //    break;
        //}

        $pageCount++;

        echo "Scraped page $pageCount \n";
    }

    fclose($fp);
}

echo "Done \n";