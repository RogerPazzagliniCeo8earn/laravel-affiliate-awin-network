<?php

/** @noinspection PhpMultipleClassDeclarationsInspection */

/** @noinspection PhpUndefinedClassInspection */

namespace SoluzioneSoftware\LaravelAffiliate\Networks\Awin;

use Carbon\Carbon;
use DateTime;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Matriphe\ISO639\ISO639;
use RuntimeException;
use SoluzioneSoftware\LaravelAffiliate\AbstractNetwork;
use SoluzioneSoftware\LaravelAffiliate\Contracts\NetworkWithProductFeeds;
use SoluzioneSoftware\LaravelAffiliate\Enums\TransactionStatus;
use SoluzioneSoftware\LaravelAffiliate\Enums\ValueType;
use SoluzioneSoftware\LaravelAffiliate\Models\Feed;
use SoluzioneSoftware\LaravelAffiliate\Models\Product;
use SoluzioneSoftware\LaravelAffiliate\Objects\CommissionRate;
use SoluzioneSoftware\LaravelAffiliate\Objects\Product as ProductObject;
use SoluzioneSoftware\LaravelAffiliate\Objects\Program;
use SoluzioneSoftware\LaravelAffiliate\Objects\Transaction;
use SoluzioneSoftware\LaravelAffiliate\Traits\ResolvesBindings;
use Throwable;

class Network extends AbstractNetwork implements NetworkWithProductFeeds
{
    use ResolvesBindings;

    const TRANSACTION_STATUS_MAPPING = [
        'approved' => TransactionStatus::CONFIRMED,
        'declined' => TransactionStatus::DECLINED,
        'deleted' => TransactionStatus::DECLINED,
        'pending' => TransactionStatus::PENDING,
    ];

    /**
     * @var string
     * @link https://wiki.awin.com/index.php/Publisher_Click_Ref
     */
    private static $TRACKING_CODE_PARAM;

    /**
     * @var string
     */
    private static $PUBLISHER_ID;

    /**
     * @var string
     */
    protected $baseUrl = 'https://api.awin.com';

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var string
     */
    private $productFeedApiKey;

    /**
     * @var array
     */
    private $productFeedExtraColumns;

    public function __construct()
    {
        parent::__construct();

        $this->apiKey = static::getNetworkConfig('api_key');
        $this->productFeedApiKey = static::getNetworkConfig('product_feed.api_key');
        $this->productFeedExtraColumns = Arr::wrap(static::getNetworkConfig('product_feed.extra_columns'));
    }

    /**
     * @inheritDoc
     */
    public static function getMaxPerPage(): ?int
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public static function getKey(): string
    {
        return 'awin';
    }

    public static function getTrackingUrl(string $advertiser, ?string $trackingCode = null, array $params = []): string
    {
        return 'https://www.awin1.com/awclick.php'
            ."?id=".static::getPublisherId()
            ."&mid=$advertiser"
            .($trackingCode ? '&'.strtolower(static::getTrackingCodeParam()).'='.$trackingCode : '');
    }

    private static function getPublisherId(): string
    {
        if (!static::$PUBLISHER_ID) {
            static::$PUBLISHER_ID = static::getNetworkConfig('publisher_id');
        }
        return static::$PUBLISHER_ID;
    }

    private static function getTrackingCodeParam(): string
    {
        if (!static::$TRACKING_CODE_PARAM) {
            static::$TRACKING_CODE_PARAM = static::getNetworkConfig('tracking_code_param');
        }
        return static::$TRACKING_CODE_PARAM;
    }

    /**
     * @inheritDoc
     * @throws BindingResolutionException
     */
    public function executeProductsCountRequest(
        ?array $programs = null,
        ?string $keyword = null,
        ?array $languages = null
    ): int {
        return $this
            ->getProductQueryBuilder($keyword, $programs, $languages)
            ->getQuery() // see: https://stackoverflow.com/a/48624056
            ->getCountForPagination();
    }

    /**
     * @param  string|null  $keyword
     * @param  string[]|null  $programs
     * @param  string[]|null  $languages
     * @return Builder
     * @throws BindingResolutionException
     */
    private function getProductQueryBuilder(?string $keyword = null, ?array $programs = null, ?array $languages = null)
    {
        $queryBuilder = static::resolveProductModelBinding()::query();

        if (!is_null($keyword)) {
            $queryBuilder->whereKey(static::resolveProductModelBinding()::search($keyword)->take(65533)->keys());
        }

        if (!is_null($programs)) {
            $queryBuilder
                ->whereExists(function (\Illuminate\Database\Query\Builder $query) use ($programs) {
                    $query
                        ->select(DB::raw(1))
                        ->from($this->getFeedsTable())
                        ->whereRaw("{$this->getProductsTable()}.feed_id = {$this->getFeedsTable()}.id")
                        ->whereIn('advertiser_id', $programs);
                });
        }

        if (!is_null($languages)) {
            $queryBuilder
                ->whereExists(function (\Illuminate\Database\Query\Builder $query) use ($languages) {
                    $query
                        ->select(DB::raw(1))
                        ->from($this->getFeedsTable())
                        ->whereRaw("{$this->getProductsTable()}.feed_id = {$this->getFeedsTable()}.id")
                        ->whereIn('language', $languages);
                });
        }

        return $queryBuilder;
    }

    /**
     * @inheritDoc
     * @throws BindingResolutionException
     */
    public function executeProductsRequest(
        ?array $programs = null,
        ?string $keyword = null,
        ?array $languages = null,
        ?string $trackingCode = null,
        int $page = 1,
        int $perPage = 10
    ): Collection {
        $this->trackingCode = $trackingCode;

        $queryBuilder = $this->getProductQueryBuilder($keyword, $programs, $languages)->with('feed');
        if (!is_null($perPage)) {
            $queryBuilder->forPage($page, $perPage);
        }

        $products = $queryBuilder->get();

        return $products
            ->map(function (Product $product) {
                return $this->productFromJson($product->toArray());
            });
    }

    public function productFromJson(array $product)
    {
        return new ProductObject(
            $this->programFromJson($product['feed']),
            $product['product_id'],
            $product['title'],
            $product['description'],
            $product['image_url'],
            floatval($product['price']),
            $product['currency'],
            $this->getDetailsUrl($product),
            $this->getProductTrackingUrl(
                $product['feed']['advertiser_id'],
                $product['product_id'],
                $this->trackingCode
            ),
            $product
        );
    }

    public function programFromJson(array $program)
    {
        return new Program(
            $this,
            $program['advertiser_id'],
            $program['advertiser_name']
        );
    }

    protected function getDetailsUrl(array $product)
    {
        return $product['details_link'];
    }

    public static function getProductTrackingUrl(
        string $advertiser,
        string $product,
        ?string $trackingCode = null,
        array $params = []
    ): string {
        return 'https://www.awin1.com/pclick.php'
            ."?p=$product"
            ."&a=".static::getPublisherId()
            ."&m=$advertiser"
            .'&'.($trackingCode ? strtolower(static::getTrackingCodeParam()).'='.$trackingCode : '');
    }

    /**
     * @inheritDoc
     * @throws BindingResolutionException
     */
    public function executeGetProduct(string $id, ?string $trackingCode = null): ?ProductObject
    {
        $this->trackingCode = $trackingCode;

        $product = static::resolveProductModelBinding()::with('feed')->where('product_id', $id)->first();
        if (is_null($product)) {
            return null;
        }

        return $this->productFromJson($product->toArray());
    }

    /**
     * @inheritDoc
     * @throws GuzzleException
     */
    public function executeTransactionsCountRequest(
        ?array $programs = null,
        ?DateTime $fromDateTime = null,
        ?DateTime $toDateTime = null
    ): int {
        return $this->executeTransactionsRequest($programs, $fromDateTime, $toDateTime)->count();
    }

    /**
     * @inheritDoc
     * @throws GuzzleException
     */
    public function executeTransactionsRequest(
        ?array $programs = null,
        ?DateTime $fromDateTime = null,
        ?DateTime $toDateTime = null,
        int $page = 1,
        ?int $perPage = null
    ): Collection {
        $fromDateTime = is_null($fromDateTime) ? Date::now() : $fromDateTime;
        $toDateTime = is_null($toDateTime) ? Date::now() : $toDateTime;

        $this->requestEndPoint = '/publishers/'.static::getPublisherId().'/transactions/';
        $this->queryParams = [
            'timezone' => 'UTC', // fixme: parametrize it
            'startDate' => $fromDateTime->format('Y-m-d\TH:i:s'),
            'endDate' => $toDateTime->format('Y-m-d\TH:i:s'),
        ];

        if (!is_null($programs)) {
            $this->queryParams['advertiserId'] = implode(',', $programs);
        }

        $response = $this->callApi();
        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new RuntimeException("Expected response status code 200. Got $statusCode.");
        }

        $transactions = json_decode($response->getBody(), true);
        if ($perPage) {
            $chunks = array_chunk($transactions, $perPage);
            $chunk = $chunks[$page - 1] ?? [];
        } else {
            $chunk = $transactions;
        }

        return collect($chunk)
            ->map(function (array $transaction) {
                return $this->transactionFromJson($transaction);
            });
    }

    /**
     * @inheritDoc
     */
    public function transactionFromJson(array $transaction)
    {
        return new Transaction(
            $transaction['advertiserId'],
            $transaction['id'],
            TransactionStatus::create(static::TRANSACTION_STATUS_MAPPING[$transaction['commissionStatus']]),
            $transaction['paidToPublisher'],
            floatval($transaction['commissionAmount']['amount']),
            $transaction['commissionAmount']['currency'],
            Carbon::parse($transaction['transactionDate']),
            $this->getTrackingCodeFromTransaction($transaction),
            $transaction
        );
    }

    /**
     * @param  array  $transaction
     * @return string|null
     */
    private function getTrackingCodeFromTransaction(array $transaction)
    {
        return Arr::get($transaction, 'clickRefs.'.static::getTrackingCodeParam());
    }

    /**
     * @inheritDoc
     * @throws GuzzleException
     * @throws Throwable
     */
    public function executeCommissionRatesCountRequest(string $programId): int
    {
        return $this->executeCommissionRatesRequest($programId)->count();
    }

    /**
     * @inheritDoc
     * @throws GuzzleException
     * @throws Throwable
     */
    public function executeCommissionRatesRequest(
        string $programId,
        int $page = 1,
        int $perPage = 100
    ): Collection {
//        fixme: consider pagination params
        $this->requestEndPoint = '/publishers/'.static::getPublisherId().'/commissiongroups';

        $this->queryParams['advertiserId'] = $programId;

        $response = $this->callApi();
        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new RuntimeException("Expected response status code 200. Got $statusCode.");
        }

        $commissionGroups = new Collection();
        $body = json_decode($response->getBody(), true);
        foreach ((array) $body['commissionGroups'] as $commissionGroup) {
            $commissionGroups->push($this->commissionRateFromJson($programId, $commissionGroup));
        }

        return $commissionGroups;
    }

    public function commissionRateFromJson(string $programId, array $commissionRate): CommissionRate
    {
        if ($commissionRate['type'] === 'fix') {
            $type = 'fixed';
            $value = $commissionRate['amount'];
        } else {
            $type = $commissionRate['type'];
            $value = $commissionRate['percentage'];
        }

        return new CommissionRate(
            $programId,
            $commissionRate['groupId'],
            $commissionRate['groupName'],
            new ValueType($type),
            (float) $value,
            $commissionRate
        );
    }

    public function downloadFeeds(string $path, callable $progressCallback)
    {
        $this->client->get(
            "https://productdata.awin.com/datafeed/list/apikey/{$this->productFeedApiKey}",
            [
                'sink' => $path,
                'progress' => $progressCallback,
            ]);
    }

    public function downloadFeedProducts(Feed $feed, string $path, callable $progressCallback)
    {
        $columns = array_merge(
            (array) static::getNetworkConfig('product_feed.extra_columns'),
            [
                'product_name',
                'description',
                'aw_product_id',
                'merchant_image_url',
                'search_price',
                'currency',
                'merchant_deep_link',
                'data_feed_id',
                'last_updated',
            ]
        );

        $url = "https://productdata.awin.com"
            ."/datafeed/download"
            ."/apikey/{$this->productFeedApiKey}"
            ."/fid/{$feed->feed_id}"
            ."/format/csv"
            ."/language/any"
            ."/delimiter/%2C" // comma
            ."/compression/zip"
            ."/columns/".implode('%2C', $columns);

        $this->client->get(
            $url,
            [
                'sink' => $path,
                'progress' => $progressCallback,
            ]
        );
    }

    public function mapProductRow(array $row): array
    {
        $mappedRow = [];
        foreach ($this->productFeedExtraColumns as $extraColumn) {
            $mappedRow[$extraColumn] = Arr::get($row, $extraColumn);
        }

        return array_merge(
            $mappedRow,
            [
                'product_id' => $row['aw_product_id'],
                'title' => $row['product_name'],
                'description' => $row['description'],
                'image_url' => $row['merchant_image_url'],
                'details_link' => $row['merchant_deep_link'],
                'price' => $row['search_price'],
                'currency' => $row['currency'],
                'last_updated_at' => $row['last_updated'] ?: null,
            ]
        );
    }

    public function mapProductFeedRow(array $row): array
    {
        return [
            'feed_id' => (string) $row['feed_id'],
            'advertiser_id' => (string) $row['advertiser_id'],
            'advertiser_name' => $row['advertiser_name'],
            'joined' => $row['membership_status'] === 'active',
            'products_count' => $row['no_of_products'],
            'imported_at' => $row['last_imported'], // fixme: consider timezone
            'region' => $row['primary_region'],
            'language' => (new ISO639)->code1ByLanguage($row['language']),
        ];
    }

    protected function getHeaders()
    {
        return array_merge(parent::getHeaders(), ['Authorization' => 'Bearer '.$this->apiKey]);
    }
}
