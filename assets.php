<?php
/**
 * The asset table, for the browser.
 *
 * dashboard.html is served no-store, but this is the same for every visitor
 * and changes only when the table does, so it is cacheable. The send dialog
 * needs the decimals, the network fee, the minimum and the address hint to
 * render anything useful, and mirroring them in JavaScript would mean two
 * copies to keep in step — and a client that quietly disagrees with the server
 * about what a send costs.
 *
 * Nothing here is account-specific, so it needs no session.
 */

require_once __DIR__ . '/crypto_assets.php';

header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');

echo json_encode(['assets' => hx_asset_public_table()]);
