<?php

namespace TronApi;

use TronApi\Api;
use TronApi\Crypto\Secp;
use TronApi\Crypto\Keccak;
use TronApi\Support\Utils;
use InvalidArgumentException;
use FurqanSiddiqui\BIP39\BIP39;
use TronApi\Support\Key as SupportKey;
use TronApi\Interfaces\WalletInterface;
use FurqanSiddiqui\BIP39\Language\English;
use TronApi\Exceptions\TronErrorException;
use TronApi\Exceptions\TransactionException;
use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39SeedGenerator;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;

class TRX implements WalletInterface
{
    protected $api;
    protected $decimals;

    public function __construct(string $apiurl, array $options = [])
    {
        $this->api = new Api($apiurl, $options);
    }

    /**
     * 生成钱包地址
     */
    public function generateAddress(): array
    {
        return $this->generateAddressWithMnemonic();
    }

    /**
     * 生成带助记词的钱包地址
     */
    public function generateAddressWithMnemonic(): array
    {
        $mnemonic = $this->mnemonicGenerate();
        $privKey = $this->mnemonicGeneratePrivateKey($mnemonic);
        $pubKey = SupportKey::privateKeyToPublicKey($privKey);
        $address = $this->getAddressHexFromPublicKey($pubKey);
        $wallet = Utils::hexToAddress($address);

        return [
            'privatekey' => $privKey,
            'publickey' => $pubKey,
            'address' => $address,
            'wallet' => $wallet,
            'mnemonic' => $mnemonic
        ];
    }

    /**
     * 从公钥获取钱包地址
     */
    public function getAddressHexFromPublicKey(string $publickey): string
    {
        $publickey = hex2bin($publickey);
        $publickey = substr($publickey, -64);
        $hash = Keccak::hash($publickey, 256);
        return strval(41) . substr($hash, 24);
    }

    /**
     * 使用Net方式验证钱包地址
     */
    public function validateAddress(string $address): bool
    {
        $res = $this->api->post('/wallet/validateaddress', [
            'address' => $address,
        ]);

        return !$res || empty($res['result']) ? false : true;
    }

    /**
     * 获取账户信息
     */
    public function accountInfo($address)
    {
        $url = "v1/accounts/{$address}?only_confirmed=true";
        $body = $this->api->get($url);

        if (isset($body['error'])) {
            throw new TransactionException($body['error']);
        }
        if (isset($body['data']) && $body['success']) {
            if (sizeof($body['data'])) {
                return $body['data'][0];
            }
        }
        throw new TransactionException('get accountInfo fail');
    }

    /**
     * 使用TXID获取交易信息
     */
    public function getTransactionInfoById($txid)
    {
        $body = $this->api->post('/wallet/gettransactioninfobyid', [
            'value' => $txid
        ]);
        if (isset($body->result->code)) {
            throw new TronErrorException(hex2bin($body->result->message));
        }
        return $body;
    }

    /**
     * 私钥获取钱包地址
     */
    public function getAddressByPrivateKey(string $privateKeyHex): Address
    {
        try {
            $addressHex = Address::ADDRESS_PREFIX . SupportKey::privateKeyToAddress($privateKeyHex);
            $addressBase58 = SupportKey::getBase58CheckAddress($addressHex);
        } catch (InvalidArgumentException $e) {
            throw new TronErrorException($e->getMessage());
        }
        $address = new Address($addressBase58, $privateKeyHex);
        $validAddress = $this->validateAddress($address->address);
        if (!$validAddress) {
            throw new TronErrorException('Invalid private key');
        }

        return $address;
    }

    public function getAccount(string $address)
    {
        $account = $this->api->post('walletsolidity/getaccount', [
            'address' => Utils::addressToHex($address),
        ]);
        return $account;
    }

    public function getAccountResource(string $address)
    {
        return $this->api->post('wallet/getaccountresource', [
            'address' => Utils::addressToHex($address),
        ]);
    }

    public function doPost($api, $data)
    {
        return $this->api->post($api, $data);
    }

    public function doGet($api, $data)
    {
        return $this->api->get($api, $data);
    }

    /**
     * 获取TRX余额
     */
    public function balance(string $address, bool $sun = false)
    {
        $account = $this->getAccount($address);
        return ($sun ? $account['balance'] : Utils::toDecimals($account['balance']));
    }

    public function transfer(string $private_key, string $from, string $to, float $amount, $message = null): Transaction
    {
        if ($from === $to) throw new InvalidArgumentException('The from and to arguments cannot be the same !');
        $data = [
            'owner_address' => $from,
            'to_address' => $to,
            'amount' => $this->toTronValue($amount)
        ];

        $transaction = $this->api->post('wallet/createtransaction', $data);

        $signature = $this->signature($private_key, $transaction);
        if (!is_null($message)) $signature['raw_data']->data = bin2hex($message);
        $broadcast = (array) $this->sendRawTransaction($signature);

        if (isset($broadcast['result']) && $broadcast['result'] == true) {
            return new Transaction(
                $transaction['txID'],
                $transaction['raw_data'],
                'PACKING'
            );
        } else {
            throw new TransactionException(hex2bin($broadcast['message']));
        }
    }

    /**
     * 获取当前区块信息
     */
    public function getNowBlock(): Block
    {
        $block = $this->api->post('wallet/getnowblock');
        $transactions = isset($block['transactions']) ? $block['transactions'] : [];
        return new Block($block['blockID'], $block['block_header'], $transactions);
    }


    /**
     * 使用blockID获取区块信息
     */
    public function getBlockByNum(int $blockID): Block
    {
        $block = $this->api->post('wallet/getblockbynum', [
            'num'   =>  intval($blockID)
        ]);

        $transactions = isset($block['transactions']) ? $block['transactions'] : [];
        return new Block($block['blockID'], $block['block_header'], $transactions);
    }

    /**
     * 使用Hash获取交易详情
     */
    public function getTransactionById(string $txHash): Transaction
    {
        $response = $this->api->post('wallet/gettransactionbyid', [
            'value' =>  $txHash
        ]);

        if (!$response) {
            throw new TronErrorException('Transaction not found');
        }

        return new Transaction(
            $response['txID'],
            $response['raw_data'],
            $response['ret'][0]['contractRet'] ?? ''
        );
    }

    /**
     * 从私钥获取助记词
     */
    public function getPhraseFromPrivateKey(string $privatekey, int $base = 16): string
    {
        if (extension_loaded('gmp')):
            $words = $this->getWords();
            srand($base);
            shuffle($words);
            $integer = gmp_init($privatekey, $base);
            $split = str_split(gmp_strval($integer), 3);
            foreach ($split as $number => $i):
                if (count($split) === ($number + 1)):
                    if (str_starts_with($i, '00')): // strlen($i) === 2 || 3 && $i in range(0,9)
                        $phrases[] = $words[intval($i) + 2000 + (strlen($i) * 10)];
                    elseif (str_starts_with($i, '0')): // strlen($i) === 1 || 2 || 3 && $i in range(0,99)
                        $phrases[] = $words[intval($i) + 1000 + (strlen($i) * 100)];
                    else:
                        $phrases[] = $words[intval($i) + 0];
                    endif;
                else:
                    $phrases[] = $words[intval($i)];
                endif;
            endforeach;
        else:
            throw new TronErrorException('gmp extension is needed !');
        endif;
        return implode(chr(32), $phrases);
    }

    public function getTransactionsRelated(string $address, bool $confirmed = null, bool $to = false, bool $from = false, bool $searchinternal = true, int $limit = 20, string $order = 'block_timestamp,desc', int $mintimestamp = null, int $maxtimestamp = null): object
    {
        $data = [
            'only_to' => $to,
            'only_from' => $from,
            'search_internal' => $searchinternal,
            'limit' => max(min($limit, 200), 20),
            'order_by' => $order
        ];
        if (!is_null($confirmed)) {
            $data[$confirmed ? 'only_confirmed' : 'only_unconfirmed'] = true;
        }

        if (!is_null($mintimestamp)) {
            $data['min_timestamp'] = date('Y-m-d\TH:i:s.v\Z', $mintimestamp);
        }
        if (!is_null($maxtimestamp)) {
            $data['max_timestamp'] = date('Y-m-d\TH:i:s.v\Z', $maxtimestamp);
        }
        $transactions = $this->api->get('v1/accounts/' . $address . '/transactions', $data);
        return $transactions;
    }

    /**
     * 获取钱包地址的交易记录
     */
    public function getTransactionsByAddress(string $address, int $limit = 20): object
    {
        return $this->getTransactionsRelated(address: $address, limit: $limit);
    }

    /**
     * 获取钱包的支出记录
     */
    public function getTransactionsFromAddress(string $address, int $limit = 20): object
    {
        return $this->getTransactionsRelated(address: $address, limit: $limit, from: true);
    }

    /**
     * 获取钱包地址的收入记录
     */
    public function getTransactionsToAddress(string $address, int $limit = 20): object
    {
        return $this->getTransactionsRelated(address: $address, limit: $limit, to: true);
    }

    /**
     * 獲取註記詞的私鑰
     */
    public function mnemonicGeneratePrivateKey(string $mnemonic, int $index = 0): string
    {
        $seedGenerator = new Bip39SeedGenerator();
        $seed = $seedGenerator->getSeed($mnemonic);

        $hdFactory = new HierarchicalKeyFactory();
        $master = $hdFactory->fromEntropy($seed);
        $hardened = $master->derivePath("44'/195'/0'/0/{$index}");

        return $hardened->getPrivateKey()->getHex();
    }

    /**
     * 獲取註記詞
     */
    public function mnemonicGenerate(int $wordCount = 24): string
    {
        $mnemonic = BIP39::fromRandom(
            wordList: English::getInstance(),
            wordCount: $wordCount
        );

        return implode(" ", $mnemonic->words);
    }

    protected function sendRawTransaction(array $response): array
    {
        if (isset($response['signature']) === false or is_array($response['signature']) === false) throw new InvalidArgumentException('response has not been signature !');
        $broadcast = $this->api->post('wallet/broadcasttransaction', $response);
        return $broadcast;
    }

    private function signature(string $private_key, array $response): array
    {
        if (!empty($private_key)):
            if (isset($response['Error'])):
                throw new TronErrorException($response['Error']);
            else:
                if (isset($response['signature'])):
                    throw new TronErrorException('response is already signed !');
                elseif (isset($response['txID']) === false):
                    throw new TronErrorException('The response does not have txID key !');
                else:
                    $signature = Secp::sign($response['txID'], $private_key);
                    $response['signature'] = array($signature);
                endif;
            endif;
        else:
            throw new TronErrorException('private key is not set');
        endif;
        return $response;
    }

    private function getWords(): array
    {
        if (file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'english.txt') === false) throw new TronErrorException('english.txt file doesn\'t exists !');
        return explode(PHP_EOL, file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'english.txt'));
    }

    /**
     * Convert float to trx format
     *
     * @param $double
     * @return int
     */
    protected function toTronValue($double, $decimals = 1e6): int
    {
        return (int) bcmul((string)$double, (string)$decimals, 0);
    }
}
