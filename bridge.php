<?php

require_once 'vendor/autoload.php';

use deemru\UnitsKit;
use deemru\WavesKit;

if( file_exists( __DIR__ . '/private.php' ) )
{
    require_once __DIR__ . '/private.php';
}
else
if( 10 ) // MAINNET
{
    $amount = UnitsKit::hexValue( 1.0 );
    $unitsPrivateKey = '0x33eb576d927573cff6ae50a9e09fc60b672a8dafdfbe3045c7f62955fc55ccb4';
    $wavesPrivateKey = '49mgaSSVQw6tDoZrHSr9rFySgHHXwgQbCRwFssboVLWX';
    $unitsDapp = '3PKgN8rfmvF7hK7RWJbpvkh59e1pQkUzero';
    $bridgeContract = '0x0000000000000000000000000000000000006a7e';

    $uk = UnitsKit::MAINNET();
    $wk = new WavesKit( 'W' );
}
else // TESTNET
{
    $amount = UnitsKit::hexValue( 1.0 );
    $unitsPrivateKey = '0x33eb576d927573cff6ae50a9e09fc60b672a8dafdfbe3045c7f62955fc55ccb4';
    $wavesPrivateKey = '49mgaSSVQw6tDoZrHSr9rFySgHHXwgQbCRwFssboVLWX';
    $unitsDapp = '3Msx4Aq69zWUKy4d1wyKnQ4ofzEDAfv5Ngf';
    $bridgeContract = '0x0000000000000000000000000000000000006a7e';

    $uk = UnitsKit::TESTNET();
    $wk = new WavesKit( 'T' );
}

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
exit;

// SUPPORT FUNCTIONS

function withdraw( $uk, $wk, $tx, $unitsDapp )
{
    $proofs = $uk->getBridgeProofs( $tx );
    if( $proofs === false )
        return false;

    $wavesProofs = [];
    foreach( $proofs as $proof )
        $wavesProofs[] = [ $proof ];

    $blockHash = substr( $tx['receipt']['blockHash'], 2 );
    $wavesProofs = [ 'list' => $wavesProofs ];
    $index = hexdec( $tx['receipt']['logs'][0]['logIndex'] );
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

    for( ;; )
    {
        $finalizedBlock = $wk->getData( 'finalizedBlock', $unitDapp );
        if( $finalizedBlock === false )
            return false;
        $finalizedHeight = getHeightByBlock( $wk, '0x' . $finalizedBlock, $unitDapp );
        if( $finalizedHeight === false )
            return false;
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
