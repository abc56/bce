<?php
//使用PHP SDK，并且使用自定义配置文件
include 'BaiduBce.phar';
require 'SampleConf.php';

use BaiduBce\BceClientConfigOptions;
use BaiduBce\BceBaseClient;
use BaiduBce\Auth\BceV1Signer;
use BaiduBce\Http\BceHttpClient;
use BaiduBce\Http\HttpContentTypes;
use BaiduBce\Util\HttpUtils;

use BaiduBce\Util\Time;
use BaiduBce\Util\MimeTypes;
use BaiduBce\Http\HttpHeaders;
use BaiduBce\Http\HttpMethod;
use BaiduBce\Services\Bos\BosClient;
use BaiduBce\Auth\SignOptions;
use BaiduBce\Services\Bos\BosOptions;

//调用配置文件中的参数
global $BOS_TEST_CONFIG;


//新建BosClient
$client = new BosClient($BOS_TEST_CONFIG);

$bucketName = "123aa";
//Bucket是否存在，若不存在创建Bucket
$exist = $client->doesBucketExist($bucketName);
if(!$exist){
    $client->createBucket($bucketName);
}

//查看Bucket列表
$response = $client->listBuckets();
foreach ($response->buckets as $bucket) {
    print $bucket->name.'</br>';
}
$objectKey = "TestFile.txt";
$string = "This is test file";
//$client->putObject($bucketName, $objectKey, $data);
$client->putObjectFromString($bucketName, $objectKey, $string);

//删除指定Object
$client->deleteObject($bucketName, $objectKey);

//删除指定Bucket，如果Bucket不为空（即Bucket中有Object存在），则Bucket无法被删除，必须清空Bucket后才能成功删除。
//$client->deleteBucket($bucketName);

class MyLLSClient extends BceBaseClient
{
    const MIN_PART_SIZE = 5242880;                // 5M
    const MAX_PUT_OBJECT_LENGTH = 5368709120;     // 5G
    const MAX_USER_METADATA_SIZE = 2048;          // 2 * 1024
    const MIN_PART_NUMBER = 1;
    const MAX_PART_NUMBER = 10000;
    const BOS_URL_PREFIX = "/";

    /**
     * @var \BaiduBce\Auth\SignerInterface
     */
    private $signer;
    private $httpClient;

    /**
     * The BosClient constructor
     *
     * @param array $config The client configuration
     */
    function __construct(array $config)
    {
        parent::__construct($config, 'Mylls');
        $this->signer = new BceV1Signer();
        $this->httpClient = new BceHttpClient();
    }

    /**
     * Create HttpClient and send request
     * @param string $httpMethod The Http request method
     * @param array $varArgs The extra arguments
     * @return mixed The Http response and headers.
     */
    public function sendRequest($httpMethod, array $varArgs)
    {
        $defaultArgs = array(
            BosOptions::CONFIG => array(),
            'bucket_name' => null,
            'key' => null,
            'body' => null,
            'headers' => array(),
            'params' => array(),
            'outputStream' => null,
            'parseUserMetadata' => false
        );

        $args = array_merge($defaultArgs, $varArgs);
        if (empty($args[BosOptions::CONFIG])) {
            $config = $this->config;
        } else {
            $config = array_merge(
                array(),
                $this->config,
                $args[BosOptions::CONFIG]
            );
        }
        if (!isset($args['headers'][HttpHeaders::CONTENT_TYPE])) {
            $args['headers'][HttpHeaders::CONTENT_TYPE] =
                HttpContentTypes::JSON;
        }
		
		if (!isset($args['headers'][HttpHeaders::BCE_REQUEST_ID])) {
            $args['headers'][HttpHeaders::BCE_REQUEST_ID] =
                "";
        }

		
		
        //$path = "/v3/live/session";
        $path = "/v3/live/preset";
        $response = $this->httpClient->sendRequest(
            $config,
            $httpMethod,
            $path,
            $args['body'],
            $args['headers'],
            $args['params'],
            $this->signer,
            $args['outputStream']
        );
/*
echo $args['headers']."</br>";
echo $args['headers'][HttpHeaders::HOST]."</br>";
echo $args['headers'][HttpHeaders::BCE_DATE]."</br>";
echo $args['headers'][HttpHeaders::BCE_REQUEST_ID]."</br>";
echo $args['headers'][HttpHeaders::AUTHORIZATION]."</br>";
echo $args['headers'][HttpHeaders::CONTENT_TYPE]."</br>";
echo $args['headers'][HttpHeaders::CONTENT_LENGTH]."</br>";
*/		
//echo $response['body'];
/*
        if ($args['outputStream'] === null) {
            $result = $this->parseJsonResult($response['body']);
        } else {
            $result = new \stdClass();
        }
        $result->metadata =
            $this->convertHttpHeadersToMetadata($response['headers']);
        if ($args['parseUserMetadata']) {
            $userMetadata = array();
            foreach ($response['headers'] as $key => $value) {
                if (StringUtils::startsWith($key, HttpHeaders::BCE_USER_METADATA_PREFIX)) {
                    $key = substr($key, strlen(HttpHeaders::BCE_USER_METADATA_PREFIX));
                    $userMetadata[urldecode($key)] = urldecode($value);
                }
            }
            $result->metadata[BosOptions::USER_METADATA] = $userMetadata;
        }
*/
        return $result;

    }

    /**
     * @param string $bucketName The bucket name.
     * @param string $key The object path.
     *
     * @return string
     */
    private function getPath($bucketName = null, $key = null)
    {
        return HttpUtils::appendUri(self::BOS_URL_PREFIX, $bucketName, $key);
    }

    /**
     * @param array $headers
     * @param array $options
     */
    private function populateRequestHeadersWithOptions(
        array &$headers,
        array &$options
    ) {
        list(
            $contentType,
            $contentSHA256,
            $userMetadata
        ) = $this->parseOptionsIgnoreExtra(
            $options,
            BosOptions::CONTENT_TYPE,
            BosOptions::CONTENT_SHA256,
            BosOptions::USER_METADATA
        );
        if ($contentType !== null) {
            $headers[HttpHeaders::CONTENT_TYPE] = $contentType;
            unset($options[BosOptions::CONTENT_TYPE]);
        }
        if ($contentSHA256 !== null) {
            $headers[HttpHeaders::BCE_CONTENT_SHA256] = $contentSHA256;
            unset($options[BosOptions::CONTENT_SHA256]);
        }
        if ($userMetadata !== null) {
            $this->populateRequestHeadersWithUserMetadata($headers, $userMetadata);
            unset($options[BosOptions::USER_METADATA]);
        }
        reset($options);
    }

    /**
     * @param array $headers
     * @param array $userMetadata
     */
    private function populateRequestHeadersWithUserMetadata(
        array &$headers,
        array $userMetadata
    ) {
        $metaSize = 0;
        foreach ($userMetadata as $key => $value) {
            $key = HttpHeaders::BCE_USER_METADATA_PREFIX
                . HttpUtils::urlEncode(trim($key));
            $value = HttpUtils::urlEncode($value);
            $metaSize += strlen($key) + strlen($value);
            if ($metaSize > BosClient::MAX_USER_METADATA_SIZE) {
                throw new BceClientException(
                    'User metadata size should not be greater than '
                    . BosClient::MAX_USER_METADATA_SIZE
                );
            }
            $headers[$key] = $value;
        }
    }

    /**
     * @param string|resource $data
     */
    private function checkData($data)
    {
        switch(gettype($data)) {
            case 'string':
                break;
            case 'resource':
                $streamMetadata = stream_get_meta_data($data);
                if (!$streamMetadata['seekable']) {
                    throw new \InvalidArgumentException(
                        '$data should be seekable.'
                    );
                }
                break;
            default:
                throw new \InvalidArgumentException(
                    'Invalid data type:' . gettype($data)
                    . ' Only string or resource is accepted.'
                );
        }
    }
}

$SSLclient = new MyLLSClient($BOS_TEST_CONFIG);


$params = array();
$config = array();

$SSLclient->sendRequest(HttpMethod::POST, array(
                BosOptions::CONFIG => $config,
                'params' => $params,
            )
			);











