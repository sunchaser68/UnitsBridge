<?php

require_once 'vendor/autoload.php';
require_once __DIR__ . '../../include/commons.php';
require_once __DIR__ . '../../config/config.php';

use deemru\UnitsKit;
use deemru\WavesKit;

if ($argc!=3)
    die("Usage: php bridge.php [password] [amount]\n");

$password=$argv[1];
$amount=$argv[2];

$amount = UnitsKit::hexValue( $amount );
$unitsPrivateKey = encrypt_decrypt('decrypt', $password, $config['unit0']['key_node']);
$wavesPrivateKey = encrypt_decrypt('decrypt', $password, $config['waves']['key_node']);
$unitsDapp = $config['unit0']['unitsdapp'];
$bridgeContract = $config['unit0']['bridgecontract'];

$wk = new WavesKit( $config['waves']['chain'] );

if ($config['waves']['chain']=='T')
	$uk = UnitsKit::TESTNET();
elseif ($config['waves']['chain']=='W')
	$uk = UnitsKit::MAINNET();
else
	die('Not supported chain');

$uk->setPrivateKey( $unitsPrivateKey );
$uk->log( 'UNITS: ' . $uk->getAddress() . ' ~ ' . $uk->stringValue( $uk->getBalance() ) . ' UNIT0' );
$wk->setPrivateKey( $wavesPrivateKey );
$uk->log( 'WAVES: ' . str_pad( $wk->getAddress(), 42 ) . ' ~ ' . $uk->stringValue( $wk->balance( null, 'WAVES' ), 8 ) . ' WAVES' );

// PHASE 1 (UNITS BRIDGE TX)
if( 10 )
{
    $uk->log( 'UNITS: sending ' . $uk->stringValue( $amount ) . ' UNIT0 from ' . $uk->getAddress() . ' to ' . $wk->getAddress() );	
    $wavesPublicKeyHash = substr( $wk->getAddress( true ), 2, 20 );
    $sendNativeInput = '0x' . '78338413' . bin2hex( str_pad( $wavesPublicKeyHash, 32, chr( 0 ) ) );
    $gasPrice = $uk->getGasPrice();
    $nonce = $uk->getNonce();

    $tx = $uk->tx( $bridgeContract, $amount, $gasPrice, $nonce, $sendNativeInput );
    $tx = $uk->txEstimateGas( $tx );
    $tx = $uk->txSign( $tx );
    $tx = $uk->txBroadcast( $tx );
    $tx = $uk->ensure( $tx );
    $uk->log( 'UNITS: sending done' );
}
else
{
    $tx['hash'] = '0x0169ce63be74489c48832d8f48cea4743c71c926d9f8515dae6e57715f76c001';
}

// PHASE 2 (WAIT FINALIZED)
if( 10 )
{
    $uk->log( 'WAVES: waiting finalized block at ' . $unitsDapp );
    if( !isset( $tx['receipt'] ) )
        $tx = $uk->txByHash( $tx['hash'] );
    for( ;; )
    {
        $finalized = waitFinalized( $wk, $tx, $unitsDapp );
        if( $finalized === false )
        {
            sleep( 10 );
            continue;
        }
        break;
    }
    $uk->log( 'WAVES: finalized block reached' );
}

// PHASE 3 (WAVES BRIDGE TX)
if( 10 )
{
    $uk->log( 'WAVES: withdrawing' );
    $tx = withdraw( $uk, $wk, $tx, $unitsDapp );
    if( !$tx )
    {
        $wk->log( 'e', 'withdraw() something went wrong' );
        exit;
    }
    $uk->log( 'WAVES: withdrawn ' . $uk->stringValue( $tx['call']['args'][3]['value'], 8 ) . ' UNIT0' );
}

$wk->log( 's', 'DONE.' );

// SUPPORT FUNCTIONS

function withdraw( $uk, $wk, $tx, $unitsDapp )
{
    [ $proofs, $index ] = $uk->getBridgeProofs( $tx );
    if( $proofs === false || $index === false )
        return false;

    $wavesProofs = [];
    foreach( $proofs as $proof )
        $wavesProofs[] = [ $proof ];

    $blockHash = substr( $tx['receipt']['blockHash'], 2 );
    $wavesProofs = [ 'list' => $wavesProofs ];
    $amount = gmp_intval( gmp_div( gmp_init( $tx['value'], 16 ), 10000000000 ) );

    $tx = $wk->txInvokeScript( $unitsDapp, 'withdraw', [ $blockHash, $wavesProofs, $index, $amount ] );
    $stx = $wk->txSign( $tx );
    $vtx = $wk->txValidate( $stx );
    return $wk->ensure( $wk->txBroadcast( $stx ) );
}

function getHeightByBlock( $wk, $blockHash, $unitDapp )
{
    $data = $wk->getData( 'block_' . $blockHash, $unitDapp );
    if( $data === false )
        return false;
    $data = base64_decode( substr( $data, 7 ) );
    return unpack( 'J', $data )[1];
}

function waitFinalized( $wk, $tx, $unitDapp )
{
    $targetHeight = getHeightByBlock( $wk, $tx['receipt']['blockHash'], $unitDapp );
    if( $targetHeight === false )
        return false;

    $lastFinalizedBlock = '';
    for( ;; )
    {
        $finalizedBlock = $wk->getData( 'finalizedBlock', $unitDapp );
        if( $finalizedBlock === false )
            return false;
        if( $lastFinalizedBlock !== $finalizedBlock )
        {
            $lastFinalizedBlock = $finalizedBlock;
            $finalizedHeight = getHeightByBlock( $wk, '0x' . $finalizedBlock, $unitDapp );
            if( $finalizedHeight === false )
                return false;
        }
        $headInfo = $wk->getData( 'chain_00000000', $unitDapp );
        if( $headInfo === false )
            return false;
        $headHeight = intval( explode( ',', $headInfo )[0] );

        $finalzedDiff = $finalizedHeight - $targetHeight;
        $headDiff = $headHeight - $targetHeight;
        $wk->log( $finalzedDiff >= 0 ? 's' : 'i', 'UNITS: target = ' . $targetHeight . ', finalized = ' . $finalizedHeight . ' (' . ( $finalzedDiff > 0 ? '+' : '' ) . $finalzedDiff . '), head = ' . $headHeight . ' (' . ( $headDiff > 0 ? '+' : '' ) . $headDiff . ')'  );

        if( $finalzedDiff >= 0 )
            break;
        sleep( 10 );
    }

    return true;
}
