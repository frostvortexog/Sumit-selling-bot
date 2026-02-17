<?php
// ===============================================
// ✅ SINGLE index.php Telegram Webhook
// ✅ Selling Bot: Add Coins + Buy Coupons + My Orders
// ✅ Admin Panel: stock, prices, free code, add/remove coupons, update UPI QR, pending deposits
// ✅ PostgreSQL (Supabase) via PDO using DATABASE_URL
// ===============================================

$BOT_TOKEN = getenv("BOT_TOKEN");
$DB_URL    = getenv("DATABASE_URL");
$ADMIN_IDS = array_filter(array_map('trim', explode(',', getenv("ADMIN_IDS") ?: "")));

if (!$BOT_TOKEN) { http_response_code(500); exit("BOT_TOKEN missing"); }
if (!$DB_URL)    { http_response_code(500); exit("DATABASE_URL missing"); }
if (count($ADMIN_IDS) === 0) { http_response_code(500); exit("ADMIN_IDS missing"); }

// ---------- PDO ----------
$db = parse_url($DB_URL);
$host = $db["host"] ?? "";
$port = $db["port"] ?? "5432";
$user = $db["user"] ?? "";
$pass = $db["pass"] ?? "";
$name = ltrim($db["path"] ?? "", "/");
$dsn  = "pgsql:host=$host;port=$port;dbname=$name";
$pdo  = new PDO($dsn, $user, $pass, [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// ---------- Telegram helper ----------
function bot($method, $data = []) {
  global $BOT_TOKEN;
  $url = "https://api.telegram.org/bot{$BOT_TOKEN}/{$method}";
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);

  // If sending file_id only, normal POST is fine
  curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
  $res = curl_exec($ch);
  curl_close($ch);
  return $res ? json_decode($res, true) : null;
}

function nowIST() {
  $dt = new DateTime("now", new DateTimeZone("Asia/Kolkata"));
  return $dt->format("Y-m-d H:i:s");
}

function isAdmin($tgId) {
  global $ADMIN_IDS;
  return in_array((string)$tgId, $ADMIN_IDS, true);
}

function mainMenuKeyboard($isAdmin = false) {
  $keyboard = [
    ["➕ Add Coins", "🛒 Buy Coupon"],
    ["📦 My Orders"]
  ];
  if ($isAdmin) $keyboard[] = ["🛠 Admin Panel"];
  return [
    "keyboard" => $keyboard,
    "resize_keyboard" => true,
    "selective" => true
  ];
}

function safeInt($s) {
  if (!is_string($s) && !is_numeric($s)) return null;
  $s = trim((string)$s);
  if (!preg_match('/^\d+$/', $s)) return null;
  return intval($s);
}

function upsertUser($tg_id, $username) {
  global $pdo;
  $stmt = $pdo->prepare("
    INSERT INTO users (tg_id, username, updated_at)
    VALUES (:tg_id, :username, NOW())
    ON CONFLICT (tg_id) DO UPDATE SET username=EXCLUDED.username, updated_at=NOW()
  ");
  $stmt->execute([":tg_id"=>$tg_id, ":username"=>$username]);
}

function getUser($tg_id) {
  global $pdo;
  $stmt = $pdo->prepare("SELECT * FROM users WHERE tg_id=:tg_id");
  $stmt->execute([":tg_id"=>$tg_id]);
  $u = $stmt->fetch();
  return $u ?: null;
}

function setUserStateTemp($tg_id, $state, $tempArr) {
  global $pdo;
  $stmt = $pdo->prepare("UPDATE users SET state=:s, temp=:t::jsonb, updated_at=NOW() WHERE tg_id=:tg_id");
  $stmt->execute([
    ":s"=>$state,
    ":t"=>json_encode($tempArr, JSON_UNESCAPED_UNICODE),
    ":tg_id"=>$tg_id
  ]);
}

function setUserState($tg_id, $state) {
  global $pdo;
  $stmt = $pdo->prepare("UPDATE users SET state=:s, updated_at=NOW() WHERE tg_id=:tg_id");
  $stmt->execute([":s"=>$state, ":tg_id"=>$tg_id]);
}

function addCoins($tg_id, $amount) {
  global $pdo;
  $stmt = $pdo->prepare("UPDATE users SET coins = coins + :a, updated_at=NOW() WHERE tg_id=:tg_id");
  $stmt->execute([":a"=>$amount, ":tg_id"=>$tg_id]);
}

function deductCoins($tg_id, $amount) {
  global $pdo;
  $stmt = $pdo->prepare("UPDATE users SET coins = coins - :a, updated_at=NOW() WHERE tg_id=:tg_id");
  $stmt->execute([":a"=>$amount, ":tg_id"=>$tg_id]);
}

function getSettings() {
  global $pdo;
  $stmt = $pdo->query("SELECT * FROM settings WHERE id=1");
  return $stmt->fetch();
}

function setSettingField($field, $value) {
  global $pdo;
  $allowed = ["upi_qr_file_id","price_500","price_1k","price_flipkart","price_bigbasket"];
  if (!in_array($field, $allowed, true)) return;
  $stmt = $pdo->prepare("UPDATE settings SET {$field}=:v, updated_at=NOW() WHERE id=1");
  $stmt->execute([":v"=>$value]);
}

function humanType($ctype) {
  switch ($ctype) {
    case "500": return "500";
    case "1k": return "1k";
    case "flipkart": return "Flipkart";
    case "bigbasket": return "BigBasket";
  }
  return $ctype;
}

function priceFieldByType($ctype) {
  switch ($ctype) {
    case "500": return "price_500";
    case "1k": return "price_1k";
    case "flipkart": return "price_flipkart";
    case "bigbasket": return "price_bigbasket";
  }
  return null;
}

function countStock($ctype) {
  global $pdo;
  $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM coupon_codes WHERE ctype=:t AND is_used=false");
  $stmt->execute([":t"=>$ctype]);
  $r = $stmt->fetch();
  return intval($r["c"] ?? 0);
}

function getOneCodeAndMarkUsed($ctype, $tg_id) {
  // returns code string or null
  global $pdo;

  // lock one row safely
  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare("
      SELECT id, code FROM coupon_codes
      WHERE ctype=:t AND is_used=false
      ORDER BY id ASC
      FOR UPDATE SKIP LOCKED
      LIMIT 1
    ");
    $stmt->execute([":t"=>$ctype]);
    $row = $stmt->fetch();
    if (!$row) {
      $pdo->rollBack();
      return null;
    }
    $upd = $pdo->prepare("
      UPDATE coupon_codes
      SET is_used=true, used_by=:u, used_at=NOW()
      WHERE id=:id
    ");
    $upd->execute([":u"=>$tg_id, ":id"=>$row["id"]]);
    $pdo->commit();
    return $row["code"];
  } catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    return null;
  }
}

function getManyCodesAndMarkUsed($ctype, $tg_id, $qty) {
  global $pdo;
  $codes = [];

  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare("
      SELECT id, code FROM coupon_codes
      WHERE ctype=:t AND is_used=false
      ORDER BY id ASC
      FOR UPDATE SKIP LOCKED
      LIMIT :q
    ");
    $stmt->bindValue(":t", $ctype);
    $stmt->bindValue(":q", $qty, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();
    if (count($rows) < $qty) {
      $pdo->rollBack();
      return null; // not enough
    }

    $ids = array_map(fn($r)=>$r["id"], $rows);
    $codes = array_map(fn($r)=>$r["code"], $rows);

    // mark used
    $in = implode(",", array_map("intval", $ids));
    $upd = $pdo->prepare("
      UPDATE coupon_codes
      SET is_used=true, used_by=:u, used_at=NOW()
      WHERE id IN ($in)
    ");
    $upd->execute([":u"=>$tg_id]);

    $pdo->commit();
    return $codes;
  } catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    return null;
  }
}

function createDeposit($tg_id, $method, $coins, $rupees) {
  global $pdo;
  $stmt = $pdo->prepare("
    INSERT INTO deposits (tg_id, method, coins_requested, rupees_amount, status, created_at)
    VALUES (:tg, :m, :c, :r, 'draft', NOW())
    RETURNING id
  ");
  $stmt->execute([":tg"=>$tg_id, ":m"=>$method, ":c"=>$coins, ":r"=>$rupees]);
  $row = $stmt->fetch();
  return intval($row["id"] ?? 0);
}

function updateDeposit($id, $fields) {
  global $pdo;
  $sets = [];
  $params = [":id"=>$id];
  foreach ($fields as $k=>$v) {
    $sets[] = "$k=:$k";
    $params[":$k"] = $v;
  }
  $sql = "UPDATE deposits SET ".implode(", ", $sets)." WHERE id=:id";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
}

function getDeposit($id) {
  global $pdo;
  $stmt = $pdo->prepare("SELECT * FROM deposits WHERE id=:id");
  $stmt->execute([":id"=>$id]);
  return $stmt->fetch();
}

function notifyAdminsDeposit($deposit, $username) {
  global $ADMIN_IDS;
  $tg_id = $deposit["tg_id"];
  $method = $deposit["method"];
  $coins = $deposit["coins_requested"];
  $rupees = $deposit["rupees_amount"];
  $gift_amount = $deposit["gift_amount"];
  $time = $deposit["created_at"];

  $methodText = ($method === "amazon") ? "Amazon Gift Card" : "UPI";
  $text = "🧾 *New Deposit Pending*\n"
        . "━━━━━━━━━━━━━━━\n"
        . "👤 User: @" . ($username ?: "unknown") . " (`$tg_id`)\n"
        . "💵 Amount: {$rupees} Rs\n"
        . "💎 Coins: {$coins}\n"
        . "💳 Method: {$methodText}\n";
  if ($method === "amazon") {
    $text .= "🎁 Gift Amount: " . ($gift_amount ?: "-") . "\n";
  }
  $text .= "📅 Time: {$time}\n"
        . "━━━━━━━━━━━━━━━\n";

  $kb = [
    "inline_keyboard" => [[
      ["text"=>"✅ Accept", "callback_data"=>"dep_accept_".$deposit["id"]],
      ["text"=>"❌ Decline", "callback_data"=>"dep_decline_".$deposit["id"]],
    ]]
  ];

  foreach ($ADMIN_IDS as $aid) {
    bot("sendMessage", [
      "chat_id" => $aid,
      "text" => $text,
      "parse_mode" => "Markdown",
      "reply_markup" => json_encode($kb)
    ]);

    // If screenshot exists, forward as photo for quick view
    if (!empty($deposit["screenshot_file_id"])) {
      bot("sendPhoto", [
        "chat_id" => $aid,
        "photo" => $deposit["screenshot_file_id"],
        "caption" => "📸 Screenshot for Deposit #".$deposit["id"]
      ]);
    }
  }
}

function listMyOrdersText($tg_id) {
  global $pdo;

  $out = "📦 *My Orders*\n━━━━━━━━━━━━━━━\n";

  // deposits
  $stmt = $pdo->prepare("SELECT * FROM deposits WHERE tg_id=:tg ORDER BY id DESC LIMIT 10");
  $stmt->execute([":tg"=>$tg_id]);
  $deps = $stmt->fetchAll();

  $out .= "💳 *Deposits (Last 10)*\n";
  if (!$deps) {
    $out .= "— No deposits yet.\n";
  } else {
    foreach ($deps as $d) {
      $m = ($d["method"] === "amazon") ? "Amazon" : "UPI";
      $out .= "• #{$d["id"]} | {$m} | {$d["rupees_amount"]}Rs => {$d["coins_requested"]}💎 | *{$d["status"]}*\n";
    }
  }

  // purchases
  $stmt2 = $pdo->prepare("SELECT * FROM purchases WHERE tg_id=:tg ORDER BY id DESC LIMIT 10");
  $stmt2->execute([":tg"=>$tg_id]);
  $ps = $stmt2->fetchAll();

  $out .= "\n🛒 *Purchases (Last 10)*\n";
  if (!$ps) {
    $out .= "— No purchases yet.\n";
  } else {
    foreach ($ps as $p) {
      $out .= "• #{$p["id"]} | ".humanType($p["ctype"])." | Qty {$p["qty"]} | Cost {$p["cost_coins"]}💎 | {$p["created_at"]}\n";
    }
  }

  $out .= "━━━━━━━━━━━━━━━";
  return $out;
}

// ---------- Read update ----------
$update = json_decode(file_get_contents("php://input"), true);
if (!$update) { echo "OK"; exit; }

$message = $update["message"] ?? null;
$callback = $update["callback_query"] ?? null;

if ($message) {
  $chat_id = $message["chat"]["id"];
  $from = $message["from"];
  $tg_id = $from["id"];
  $username = $from["username"] ?? "";
  $text = $message["text"] ?? "";
  $photo = $message["photo"] ?? null;

  upsertUser($tg_id, $username);
  $user = getUser($tg_id);
  $admin = isAdmin($tg_id);

  // /start
  if (isset($message["text"]) && preg_match('/^\/start/', $message["text"])) {
    bot("sendMessage", [
      "chat_id" => $chat_id,
      "text" => "Welcome! Use the menu below 👇",
      "reply_markup" => json_encode(mainMenuKeyboard($admin))
    ]);
    setUserStateTemp($tg_id, "idle", []);
    echo "OK"; exit;
  }

  // Admin panel command
  if ($admin && isset($message["text"]) && $message["text"] === "/admin") {
    bot("sendMessage", [
      "chat_id" => $chat_id,
      "text" => "🛠 Admin Panel opened.",
      "reply_markup" => json_encode(mainMenuKeyboard(true))
    ]);
    echo "OK"; exit;
  }

  // Handle menu buttons
  if ($text === "➕ Add Coins") {
    $msg = "💳 Select Payment Method:\n\n⚠️ Under Maintenance:\n\n🛠️ PhonePe Gift Card\n\nPlease use other methods for deposit.";
    $kb = [
      "inline_keyboard" => [
        [
          ["text"=>"🟠 Amazon Gift Card", "callback_data"=>"pay_amazon"],
          ["text"=>"🟣 UPI", "callback_data"=>"pay_upi"]
        ]
      ]
    ];
    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>$msg,
      "reply_markup"=>json_encode($kb)
    ]);
    setUserStateTemp($tg_id, "idle", []);
    echo "OK"; exit;
  }

  if ($text === "🛒 Buy Coupon") {
    $settings = getSettings();
    $types = ["500","1k","flipkart","bigbasket"];

    $rows = [];
    foreach ($types as $t) {
      $pf = priceFieldByType($t);
      $price = intval($settings[$pf] ?? 0);
      $stock = countStock($t);
      $rows[] = [[
        "text" => "🛒 Buy ".humanType($t)." (".$price."💎) [Stock: ".$stock."]",
        "callback_data" => "buytype_".$t
      ]];
    }

    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"Select a coupon type:",
      "reply_markup"=>json_encode(["inline_keyboard"=>$rows])
    ]);
    setUserStateTemp($tg_id, "idle", []);
    echo "OK"; exit;
  }

  if ($text === "📦 My Orders") {
    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>listMyOrdersText($tg_id),
      "parse_mode"=>"Markdown"
    ]);
    echo "OK"; exit;
  }

  if ($admin && $text === "🛠 Admin Panel") {
    $kb = [
      "keyboard" => [
        ["📦 Stock", "💰 Change Prices"],
        ["🎁 Get a Code Free", "➕ Add Coupon"],
        ["➖ Remove Coupon", "🧾 Pending Deposits"],
        ["🖼 Update UPI QR", "⬅️ Back"]
      ],
      "resize_keyboard"=>true
    ];
    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"🛠 Admin Panel:", "reply_markup"=>json_encode($kb)]);
    setUserStateTemp($tg_id, "admin_idle", []);
    echo "OK"; exit;
  }

  if ($admin && $text === "⬅️ Back") {
    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"Back to main menu.",
      "reply_markup"=>json_encode(mainMenuKeyboard(true))
    ]);
    setUserStateTemp($tg_id, "idle", []);
    echo "OK"; exit;
  }

  // ----- State machine -----
  $state = $user["state"] ?? "idle";
  $temp = json_decode($user["temp"] ?? "{}", true) ?: [];

  // User entering coin amount
  if ($state === "await_coin_amount") {
    $amount = safeInt($text);
    if ($amount === null) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Please send a valid number (minimum 20)."]);
      echo "OK"; exit;
    }
    if ($amount < 20) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Minimum coins is 20. Send again."]);
      echo "OK"; exit;
    }

    $method = $temp["method"] ?? "amazon";
    $depositId = createDeposit($tg_id, $method, $amount, $amount);

    $methodText = ($method === "amazon") ? "Amazon Gift Card" : "UPI";
    $summary =
"📝 Order Summary:
━━━━━━━━━━━━━━━
💹 Rate: 1 Rs = 1 Diamond 💎
💵 Amount: {$amount} Rs
💎 Diamonds to Receive: {$amount} 💎
💳 Method: {$methodText}
📅 Time: ".nowIST()."
━━━━━━━━━━━━━━━
Click below to proceed.";

    if ($method === "amazon") {
      $kb = ["inline_keyboard"=>[
        [["text"=>"✅ Submit a Gift Card", "callback_data"=>"submit_gift_".$depositId]]
      ]];
    } else {
      $settings = getSettings();
      $qr = $settings["upi_qr_file_id"] ?? null;

      if ($qr) {
        bot("sendPhoto", [
          "chat_id"=>$chat_id,
          "photo"=>$qr,
          "caption"=>"📌 Pay via UPI QR then click: ✅ I have done the payment"
        ]);
      } else {
        bot("sendMessage", [
          "chat_id"=>$chat_id,
          "text"=>"⚠️ UPI QR not set by admin yet. Please try later or use Amazon method."
        ]);
      }

      $kb = ["inline_keyboard"=>[
        [["text"=>"✅ I have done the payment", "callback_data"=>"done_upi_".$depositId]]
      ]];
    }

    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>$summary,
      "reply_markup"=>json_encode($kb)
    ]);

    setUserStateTemp($tg_id, "idle", []);
    echo "OK"; exit;
  }

  // Amazon: waiting gift amount text
  if ($state === "await_gift_amount") {
    $giftAmount = trim($text);
    if ($giftAmount === "") {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Send the Amazon Gift Card Amount (text/number)."]);
      echo "OK"; exit;
    }

    $depositId = intval($temp["deposit_id"] ?? 0);
    if ($depositId <= 0) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Session expired. Please start again from Add Coins."]);
      setUserStateTemp($tg_id, "idle", []);
      echo "OK"; exit;
    }

    updateDeposit($depositId, ["gift_amount"=>$giftAmount]);

    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"📸 Now upload a screenshot of the gift card:"
    ]);

    setUserStateTemp($tg_id, "await_gift_screenshot", ["deposit_id"=>$depositId]);
    echo "OK"; exit;
  }

  // Amazon: waiting screenshot
  if ($state === "await_gift_screenshot") {
    $depositId = intval($temp["deposit_id"] ?? 0);
    if ($depositId <= 0) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Session expired. Please start again from Add Coins."]);
      setUserStateTemp($tg_id, "idle", []);
      echo "OK"; exit;
    }

    if (!$photo) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Please upload a screenshot (photo)."]);
      echo "OK"; exit;
    }

    $file_id = end($photo)["file_id"];
    updateDeposit($depositId, ["screenshot_file_id"=>$file_id, "status"=>"pending"]);

    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"✅ Admin is checking your code/payment. Please wait for approval."
    ]);

    $deposit = getDeposit($depositId);
    notifyAdminsDeposit($deposit, $username);

    setUserStateTemp($tg_id, "idle", []);
    echo "OK"; exit;
  }

  // UPI: waiting screenshot
  if ($state === "await_upi_screenshot") {
    $depositId = intval($temp["deposit_id"] ?? 0);
    if ($depositId <= 0) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Session expired. Please start again from Add Coins."]);
      setUserStateTemp($tg_id, "idle", []);
      echo "OK"; exit;
    }

    if (!$photo) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Please upload a screenshot (photo)."]);
      echo "OK"; exit;
    }

    $file_id = end($photo)["file_id"];
    updateDeposit($depositId, ["screenshot_file_id"=>$file_id, "status"=>"pending"]);

    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"✅ Admin is checking your payment. Please wait for approval."
    ]);

    $deposit = getDeposit($depositId);
    notifyAdminsDeposit($deposit, $username);

    setUserStateTemp($tg_id, "idle", []);
    echo "OK"; exit;
  }

  // Buy coupon: waiting quantity
  if ($state === "await_buy_qty") {
    $qty = safeInt($text);
    if ($qty === null || $qty <= 0) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Please send a valid quantity number."]);
      echo "OK"; exit;
    }

    $ctype = $temp["ctype"] ?? "";
    if (!in_array($ctype, ["500","1k","flipkart","bigbasket"], true)) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Session expired. Please open Buy Coupon again."]);
      setUserStateTemp($tg_id, "idle", []);
      echo "OK"; exit;
    }

    $settings = getSettings();
    $pf = priceFieldByType($ctype);
    $price = intval($settings[$pf] ?? 0);
    $need = $price * $qty;

    $user = getUser($tg_id); // refresh
    $balance = intval($user["coins"] ?? 0);

    $stock = countStock($ctype);
    if ($stock < $qty) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Not enough stock! Available: {$stock}"]);
      setUserStateTemp($tg_id, "idle", []);
      echo "OK"; exit;
    }

    if ($balance < $need) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Not enough diamonds!\nNeeded: {$need} | You have: {$balance}"]);
      setUserStateTemp($tg_id, "idle", []);
      echo "OK"; exit;
    }

    // Take codes (transaction) and deduct coins
    $codes = getManyCodesAndMarkUsed($ctype, $tg_id, $qty);
    if ($codes === null) {
      $stock = countStock($ctype);
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Not enough stock! Available: {$stock}"]);
      setUserStateTemp($tg_id, "idle", []);
      echo "OK"; exit;
    }

    // Deduct coins
    deductCoins($tg_id, $need);

    
      // Save purchase
    $codesText = implode("\n", $codes);
    $stmt = $pdo->prepare("
      INSERT INTO purchases (tg_id, ctype, qty, cost_coins, codes_sent, created_at)
      VALUES (:tg, :ct, :q, :cost, :codes, NOW())
      RETURNING id
    ");
    $stmt->execute([
      ":tg"=>$tg_id, ":ct"=>$ctype, ":q"=>$qty, ":cost"=>$need, ":codes"=>$codesText
    ]);
    $p = $stmt->fetch();
    $pid = intval($p["id"] ?? 0);

    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"✅ Purchase Successful!\n━━━━━━━━━━━━━━━\n🧾 Order #{$pid}\n🎟 Type: ".humanType($ctype)."\n📦 Qty: {$qty}\n💎 Cost: {$need}\n━━━━━━━━━━━━━━━\n\n🎁 Your Codes:\n{$codesText}"
    ]);

    setUserStateTemp($tg_id, "idle", []);
    echo "OK"; exit;
  }

  // -------- ADMIN STATES --------
  if ($admin && $text === "📦 Stock") {
    $settings = getSettings();
    $types = ["500","1k","flipkart","bigbasket"];

    $lines = ["📦 *Stock & Prices*", "━━━━━━━━━━━━━━━"];
    foreach ($types as $t) {
      $pf = priceFieldByType($t);
      $price = intval($settings[$pf] ?? 0);
      $stock = countStock($t);
      $lines[] = "• ".humanType($t)." | Price: {$price}💎 | Stock: {$stock}";
    }
    $lines[] = "━━━━━━━━━━━━━━━";
    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>implode("\n",$lines), "parse_mode"=>"Markdown"]);
    echo "OK"; exit;
  }

  if ($admin && $text === "🧾 Pending Deposits") {
    $stmt = $pdo->query("SELECT d.*, u.username FROM deposits d LEFT JOIN users u ON u.tg_id=d.tg_id WHERE d.status='pending' ORDER BY d.id DESC LIMIT 10");
    $rows = $stmt->fetchAll();

    if (!$rows) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"✅ No pending deposits."]);
      echo "OK"; exit;
    }

    foreach ($rows as $d) {
      $methodText = ($d["method"] === "amazon") ? "Amazon Gift Card" : "UPI";
      $textx = "🧾 Pending Deposit #{$d["id"]}\n"
             . "👤 @".($d["username"] ?: "unknown")." ({$d["tg_id"]})\n"
             . "💵 {$d["rupees_amount"]} Rs | 💎 {$d["coins_requested"]}\n"
             . "💳 {$methodText}\n"
             . "📅 {$d["created_at"]}";

      $kb = ["inline_keyboard"=>[[
        ["text"=>"✅ Accept", "callback_data"=>"dep_accept_".$d["id"]],
        ["text"=>"❌ Decline", "callback_data"=>"dep_decline_".$d["id"]],
      ]]];

      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>$textx, "reply_markup"=>json_encode($kb)]);
      if (!empty($d["screenshot_file_id"])) {
        bot("sendPhoto", ["chat_id"=>$chat_id, "photo"=>$d["screenshot_file_id"], "caption"=>"📸 Screenshot for #".$d["id"]]);
      }
    }
    echo "OK"; exit;
  }

  if ($admin && $text === "🖼 Update UPI QR") {
    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"📸 Send the new UPI QR image (photo):"]);
    setUserStateTemp($tg_id, "admin_wait_qr", []);
    echo "OK"; exit;
  }

  if ($admin && $state === "admin_wait_qr") {
    if (!$photo) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Please upload a photo QR."]);
      echo "OK"; exit;
    }
    $file_id = end($photo)["file_id"];
    setSettingField("upi_qr_file_id", $file_id);
    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"✅ UPI QR updated successfully."]);
    setUserStateTemp($tg_id, "admin_idle", []);
    echo "OK"; exit;
  }

  if ($admin && $text === "➕ Add Coupon") {
    $kb = ["inline_keyboard"=>[
      [["text"=>"500", "callback_data"=>"admin_add_500"], ["text"=>"1k", "callback_data"=>"admin_add_1k"]],
      [["text"=>"Flipkart", "callback_data"=>"admin_add_flipkart"], ["text"=>"BigBasket", "callback_data"=>"admin_add_bigbasket"]],
    ]];
    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"Select type to add coupons:", "reply_markup"=>json_encode($kb)]);
    echo "OK"; exit;
  }

  if ($admin && $text === "➖ Remove Coupon") {
    $kb = ["inline_keyboard"=>[
      [["text"=>"500", "callback_data"=>"admin_rem_500"], ["text"=>"1k", "callback_data"=>"admin_rem_1k"]],
      [["text"=>"Flipkart", "callback_data"=>"admin_rem_flipkart"], ["text"=>"BigBasket", "callback_data"=>"admin_rem_bigbasket"]],
    ]];
    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"Select type to remove coupons:", "reply_markup"=>json_encode($kb)]);
    echo "OK"; exit;
  }

  if ($admin && $text === "💰 Change Prices") {
    $kb = ["inline_keyboard"=>[
      [["text"=>"500", "callback_data"=>"admin_price_500"], ["text"=>"1k", "callback_data"=>"admin_price_1k"]],
      [["text"=>"Flipkart", "callback_data"=>"admin_price_flipkart"], ["text"=>"BigBasket", "callback_data"=>"admin_price_bigbasket"]],
    ]];
    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"Select type to change price:", "reply_markup"=>json_encode($kb)]);
    echo "OK"; exit;
  }

  if ($admin && $text === "🎁 Get a Code Free") {
    $kb = ["inline_keyboard"=>[
      [["text"=>"500", "callback_data"=>"admin_free_500"], ["text"=>"1k", "callback_data"=>"admin_free_1k"]],
      [["text"=>"Flipkart", "callback_data"=>"admin_free_flipkart"], ["text"=>"BigBasket", "callback_data"=>"admin_free_bigbasket"]],
    ]];
    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"Select type to get 1 free code:", "reply_markup"=>json_encode($kb)]);
    echo "OK"; exit;
  }

  // admin add bulk codes (text)
  if ($admin && $state === "admin_wait_add_codes") {
    $ctype = $temp["ctype"] ?? "";
    if (!in_array($ctype, ["500","1k","flipkart","bigbasket"], true)) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Invalid type."]);
      setUserStateTemp($tg_id, "admin_idle", []);
      echo "OK"; exit;
    }

    $lines = preg_split("/\r\n|\n|\r/", trim($text));
    $lines = array_values(array_filter(array_map('trim',$lines), fn($x)=>$x!==""));
    if (count($lines) === 0) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Send codes line-by-line (at least 1)."]);
      echo "OK"; exit;
    }

    $stmt = $pdo->prepare("INSERT INTO coupon_codes (ctype, code, is_used, added_at) VALUES (:t, :c, false, NOW())");
    $added = 0;
    foreach ($lines as $c) {
      $stmt->execute([":t"=>$ctype, ":c"=>$c]);
      $added++;
    }

    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"✅ Added {$added} codes to ".humanType($ctype)."."]);
    setUserStateTemp($tg_id, "admin_idle", []);
    echo "OK"; exit;
  }

  // admin remove quantity
  if ($admin && $state === "admin_wait_remove_qty") {
    $qty = safeInt($text);
    if ($qty === null || $qty <= 0) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Send a valid number to remove."]);
      echo "OK"; exit;
    }

    $ctype = $temp["ctype"] ?? "";
    if (!in_array($ctype, ["500","1k","flipkart","bigbasket"], true)) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Invalid type."]);
      setUserStateTemp($tg_id, "admin_idle", []);
      echo "OK"; exit;
    }

    // delete oldest unused
    $pdo->beginTransaction();
    $sel = $pdo->prepare("SELECT id FROM coupon_codes WHERE ctype=:t AND is_used=false ORDER BY id ASC LIMIT :q FOR UPDATE SKIP LOCKED");
    $sel->bindValue(":t", $ctype);
    $sel->bindValue(":q", $qty, PDO::PARAM_INT);
    $sel->execute();
    $rows = $sel->fetchAll();
    $ids = array_map(fn($r)=>intval($r["id"]), $rows);

    if (count($ids) === 0) {
      $pdo->rollBack();
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ No unused codes to remove."]);
      setUserStateTemp($tg_id, "admin_idle", []);
      echo "OK"; exit;
    }

    $in = implode(",", $ids);
    $del = $pdo->prepare("DELETE FROM coupon_codes WHERE id IN ($in)");
    $del->execute();
    $pdo->commit();

    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"✅ Removed ".count($ids)." codes from ".humanType($ctype)."."]);
    setUserStateTemp($tg_id, "admin_idle", []);
    echo "OK"; exit;
  }

  // admin change price waiting
  if ($admin && $state === "admin_wait_price") {
    $new = safeInt($text);
    if ($new === null || $new <= 0) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Send a valid new price (number)."]);
      echo "OK"; exit;
    }

    $ctype = $temp["ctype"] ?? "";
    $pf = priceFieldByType($ctype);
    if (!$pf) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Invalid type."]);
      setUserStateTemp($tg_id, "admin_idle", []);
      echo "OK"; exit;
    }

    setSettingField($pf, $new);
    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"✅ Price updated: ".humanType($ctype)." = {$new}💎"]);
    setUserStateTemp($tg_id, "admin_idle", []);
    echo "OK"; exit;
  }

  // Default fallback
  bot("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>"Use the menu buttons 👇",
    "reply_markup"=>json_encode(mainMenuKeyboard($admin))
  ]);
  echo "OK"; exit;
}

// ---------- CALLBACK QUERIES ----------
if ($callback) {
  $cid = $callback["id"];
  $from = $callback["from"];
  $tg_id = $from["id"];
  $username = $from["username"] ?? "";
  $data = $callback["data"] ?? "";
  $chat_id = $callback["message"]["chat"]["id"];

  upsertUser($tg_id, $username);
  $user = getUser($tg_id);
  $admin = isAdmin($tg_id);

  bot("answerCallbackQuery", ["callback_query_id"=>$cid]);

  // Payment method selected
  if ($data === "pay_amazon" || $data === "pay_upi") {
    $method = ($data === "pay_amazon") ? "amazon" : "upi";
    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"Enter the number of coins to add (Method: ".($method==="amazon"?"Amazon":"Upi")."):\n\n✅ Minimum: 20"
    ]);
    setUserStateTemp($tg_id, "await_coin_amount", ["method"=>$method]);
    echo "OK"; exit;
  }

  // Submit gift card
  if (preg_match('/^submit_gift_(\d+)$/', $data, $m)) {
    $depositId = intval($m[1]);
    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"Enter your Amazon Gift Card Amount for They Enter:"
    ]);
    setUserStateTemp($tg_id, "await_gift_amount", ["deposit_id"=>$depositId]);
    echo "OK"; exit;
  }

  // UPI done
  if (preg_match('/^done_upi_(\d+)$/', $data, $m)) {
    $depositId = intval($m[1]);
    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"📸 Now upload a screenshot of the Payment:"
    ]);
    setUserStateTemp($tg_id, "await_upi_screenshot", ["deposit_id"=>$depositId]);
    echo "OK"; exit;
  }

  // Buy type selected
  if (preg_match('/^buytype_(500|1k|flipkart|bigbasket)$/', $data, $m)) {
    $ctype = $m[1];
    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"Please send the quantity:"]);
    setUserStateTemp($tg_id, "await_buy_qty", ["ctype"=>$ctype]);
    echo "OK"; exit;
  }

  // Admin accept/decline deposit
  if ($admin && preg_match('/^dep_(accept|decline)_(\d+)$/', $data, $m)) {
    $action = $m[1];
    $depId = intval($m[2]);

    $dep = getDeposit($depId);
    if (!$dep) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Deposit not found."]);
      echo "OK"; exit;
    }
    if ($dep["status"] !== "pending") {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"⚠️ Already processed. Status: ".$dep["status"]]);
      echo "OK"; exit;
    }

    if ($action === "decline") {
      updateDeposit($depId, ["status"=>"declined", "reviewed_by"=>$tg_id, "reviewed_at"=>date("c")]);
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Declined Deposit #{$depId}"]);
      bot("sendMessage", ["chat_id"=>$dep["tg_id"], "text"=>"❌ Your deposit #{$depId} was declined."]);
      echo "OK"; exit;
    }

    // accept
    updateDeposit($depId, ["status"=>"accepted", "reviewed_by"=>$tg_id, "reviewed_at"=>date("c")]);
    addCoins($dep["tg_id"], intval($dep["coins_requested"]));

    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"✅ Accepted Deposit #{$depId} (Added {$dep["coins_requested"]}💎)"]);
    bot("sendMessage", ["chat_id"=>$dep["tg_id"], "text"=>"✅ Deposit approved! Added {$dep["coins_requested"]}💎 to your balance."]);
    echo "OK"; exit;
  }

  // Admin: add coupon type selected
  if ($admin && preg_match('/^admin_add_(500|1k|flipkart|bigbasket)$/', $data, $m)) {
    $ctype = $m[1];
    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"Send coupons for ".humanType($ctype)." line by line (bulk):"]);
    setUserStateTemp($tg_id, "admin_wait_add_codes", ["ctype"=>$ctype]);
    echo "OK"; exit;
  }

  // Admin: remove coupon type selected
  if ($admin && preg_match('/^admin_rem_(500|1k|flipkart|bigbasket)$/', $data, $m)) {
    $ctype = $m[1];
    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"How many ".humanType($ctype)." coupons you want to remove? Send a number:"]);
    setUserStateTemp($tg_id, "admin_wait_remove_qty", ["ctype"=>$ctype]);
    echo "OK"; exit;
  }

  // Admin: change price type selected
  if ($admin && preg_match('/^admin_price_(500|1k|flipkart|bigbasket)$/', $data, $m)) {
    $ctype = $m[1];
    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"Send new price (in 💎) for ".humanType($ctype).":"]);
    setUserStateTemp($tg_id, "admin_wait_price", ["ctype"=>$ctype]);
    echo "OK"; exit;
  }

  // Admin: get free code
  if ($admin && preg_match('/^admin_free_(500|1k|flipkart|bigbasket)$/', $data, $m)) {
    $ctype = $m[1];
    $code = getOneCodeAndMarkUsed($ctype, $tg_id);
    if (!$code) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ No stock for ".humanType($ctype)."."]);
      echo "OK"; exit;
    }
    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"🎁 Free Code (".humanType($ctype)."):\n`{$code}`", "parse_mode"=>"Markdown"]);
    echo "OK"; exit;
  }

  echo "OK"; exit;
}

echo "OK";

