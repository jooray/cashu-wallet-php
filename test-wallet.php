#!/usr/bin/env php
<?php
/**
 * Interactive CLI Testing Tool for CashuWallet
 *
 * Tests the CashuWallet library with real mints.
 * Walks users through minting, melting, and other operations.
 */

declare(strict_types=1);

require_once __DIR__ . '/CashuWallet.php';

use Cashu\Wallet;
use Cashu\Proof;
use Cashu\ProofState;
use Cashu\BigInt;
use Cashu\Mnemonic;
use Cashu\CashuException;
use Cashu\InsufficientBalanceException;
use Cashu\TokenSerializer;
use Cashu\Unit;
use Cashu\LightningAddress;

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Prompt user for input with optional default value
 */
function prompt(string $msg, string $default = ''): string
{
    $defaultStr = $default !== '' ? " [$default]" : '';
    $fullPrompt = "$msg$defaultStr: ";

    // Use readline if available (better paste handling)
    if (function_exists('readline')) {
        $input = readline($fullPrompt);
        if ($input === false) {
            $input = '';
        }
    } else {
        echo $fullPrompt;
        $input = fgets(STDIN) ?: '';
    }

    $input = trim($input);
    return $input !== '' ? $input : $default;
}

/**
 * Print success message in green
 */
function success(string $msg): void
{
    echo "\033[32m✓ $msg\033[0m\n";
}

/**
 * Print error message in red
 */
function error(string $msg): void
{
    echo "\033[31m✗ $msg\033[0m\n";
}

/**
 * Print info message in yellow
 */
function info(string $msg): void
{
    echo "\033[33m$msg\033[0m\n";
}

/**
 * Clear line and print
 */
function printLine(string $msg): void
{
    echo "\r\033[K$msg";
}

// ============================================================================
// MAIN CLI CLASS
// ============================================================================

class CashuWalletTester
{
    private const DEFAULT_MINT_URL = 'https://mint.coinos.io';

    private ?Wallet $wallet = null;
    private string $selectedUnit = 'sat';

    /** @var Proof[] In-memory proof storage */
    private array $proofs = [];

    public function run(): void
    {
        $this->printHeader();
        $this->setup();
        $this->mainLoop();
    }

    private function printHeader(): void
    {
        echo "\n";
        echo "===========================================\n";
        echo "   CashuWallet Interactive Tester\n";
        echo "===========================================\n";
        echo "\n";
    }

    private function setup(): void
    {
        // Get mint URL
        $mintUrl = prompt('Enter mint URL', self::DEFAULT_MINT_URL);

        echo "\nQuerying mint for supported units...\n";

        // Query available units
        try {
            $units = Wallet::getSupportedUnits($mintUrl);
            $activeUnits = array_filter($units, fn($info) => $info['activeCount'] > 0);

            if (empty($activeUnits)) {
                error("No active units found on this mint");
                exit(1);
            }

            // Display available units
            echo "\nAvailable units:\n";
            $unitCodes = array_keys($activeUnits);
            foreach ($unitCodes as $i => $code) {
                $info = $activeUnits[$code];
                $num = $i + 1;
                $unitName = strtoupper($code);
                $activeCount = $info['activeCount'];
                $keysetWord = $activeCount === 1 ? 'keyset' : 'keysets';
                echo "  [$num] $unitName ($activeCount active $keysetWord)\n";
            }
            echo "\n";

            // Select unit
            $defaultChoice = '1';
            $choice = prompt('Select unit', $defaultChoice);
            $choiceIndex = (int)$choice - 1;

            if ($choiceIndex < 0 || $choiceIndex >= count($unitCodes)) {
                $choiceIndex = 0;
            }

            $this->selectedUnit = $unitCodes[$choiceIndex];
            echo "\n";

        } catch (CashuException $e) {
            error("Failed to query mint: " . $e->getMessage());
            exit(1);
        }

        // Backend selection
        echo "Select math backend:\n";
        echo "  [1] GMP (faster, if available)\n";
        echo "  [2] BCMath (fallback)\n";
        $backendChoice = prompt('Choice', '1');

        if ($backendChoice === '2') {
            info("\nNote: BCMath selected. GMP would be faster if available.");
        }

        echo "\n";

        // Initialize wallet and connect to mint
        $this->connect($mintUrl);
    }

    private function connect(string $mintUrl): void
    {
        echo "Connecting to mint...\n";

        try {
            $dbPath = __DIR__ . '/test-wallet.db';
            $this->wallet = new Wallet($mintUrl, $this->selectedUnit, $dbPath);
            $this->wallet->loadMint();

            $info = $this->wallet->getMintInfo();
            $name = $info['name'] ?? 'Unknown';
            $keysetId = $this->wallet->getActiveKeysetId();
            $unit = $this->wallet->getUnit();
            $unitName = strtoupper($unit);
            $backend = BigInt::isUsingGmp() ? 'GMP' : 'BCMath';

            success("Connected to: $name");
            echo "  - Unit: $unitName\n";
            echo "  - Active keyset: $keysetId\n";
            echo "  - Math backend: $backend\n";
            echo "\n";

            // Generate seed for this session
            $this->initializeSeed();

        } catch (CashuException $e) {
            error("Failed to connect: " . $e->getMessage());
            exit(1);
        }
    }

    private function initializeSeed(): void
    {
        echo "Generating wallet seed...\n";
        $mnemonic = $this->wallet->generateMnemonic();

        echo "\n";
        echo "\033[43m\033[30m ========================================= \033[0m\n";
        echo "\033[43m\033[30m   IMPORTANT: SAVE YOUR SEED PHRASE!      \033[0m\n";
        echo "\033[43m\033[30m ========================================= \033[0m\n";
        echo "\n";
        echo "Your 12-word seed phrase:\n";
        echo "\n";

        $words = explode(' ', $mnemonic);
        for ($i = 0; $i < count($words); $i++) {
            $num = str_pad((string)($i + 1), 2, ' ', STR_PAD_LEFT);
            echo "  \033[36m$num. {$words[$i]}\033[0m\n";
        }

        echo "\n";
        echo "\033[31mWARNING: Write these words down and store them safely!\033[0m\n";
        echo "If you lose this seed phrase, you CANNOT recover your tokens.\n";
        echo "Anyone with this phrase can spend your tokens.\n";
        echo "\n";

        $confirm = prompt('I have saved my seed phrase', 'yes');
        if (strtolower($confirm) !== 'yes' && strtolower($confirm) !== 'y') {
            echo "\n";
            info("Please save your seed phrase before continuing.");
            echo "Seed: \033[33m$mnemonic\033[0m\n";
            echo "\n";
        }

        success("Wallet initialized with seed");
        echo "\n";
    }

    private function mainLoop(): void
    {
        while (true) {
            $this->printMenu();
            $choice = prompt('Choice');
            echo "\n";

            switch ($choice) {
                case '1':
                    $this->mintTokens();
                    break;
                case '2':
                    $this->meltTokens();
                    break;
                case '3':
                    $this->payLightningAddress();
                    break;
                case '4':
                    $this->checkBalance();
                    break;
                case '5':
                    $this->showToken();
                    break;
                case '6':
                    $this->receiveToken();
                    break;
                case '7':
                    $this->checkProofState();
                    break;
                case '8':
                    $this->manageSeedPhrase();
                    break;
                case '9':
                    $this->restoreWallet();
                    break;
                case '0':
                    echo "Goodbye!\n";
                    exit(0);
                default:
                    error("Invalid choice");
            }

            echo "\n";
        }
    }

    private function printMenu(): void
    {
        $balance = Wallet::sumProofs($this->proofs);
        $formattedBalance = $this->wallet->formatAmount($balance);

        echo "=== Main Menu ===\n";
        echo "Current balance: $formattedBalance\n";
        echo "\n";
        echo "[1] Mint tokens (Lightning -> Tokens)\n";
        echo "[2] Melt tokens (Tokens -> Lightning invoice)\n";
        echo "[3] Pay Lightning Address (Tokens -> user@domain)\n";
        echo "[4] Check balance\n";
        echo "[5] Send tokens (create Cashu token)\n";
        echo "[6] Receive token\n";
        echo "[7] Check proof state\n";
        echo "[8] Show seed phrase\n";
        echo "[9] Restore from seed phrase\n";
        echo "[0] Exit\n";
        echo "\n";
    }

    private function mintTokens(): void
    {
        $unitHelper = $this->wallet->getUnitHelper();
        $unitName = $unitHelper->getName();
        $example = $unitHelper->getExampleAmount();

        $input = prompt("Enter amount (e.g., $example $unitName)", $example);

        try {
            $amount = $this->wallet->parseAmount($input);
        } catch (\InvalidArgumentException $e) {
            error("Invalid amount format");
            return;
        }

        if ($amount <= 0) {
            error("Amount must be positive");
            return;
        }

        echo "Requesting mint quote for " . $this->wallet->formatAmount($amount) . "...\n";

        try {
            $quote = $this->wallet->requestMintQuote($amount);

            echo "\nPay this Lightning invoice:\n";
            echo "\033[36m{$quote->request}\033[0m\n";
            echo "\n";
            info("Waiting for payment... (press Ctrl+C to cancel)");

            // Poll for payment
            $dots = 0;
            while (true) {
                $quote = $this->wallet->checkMintQuote($quote->quote);

                if ($quote->isPaid() || $quote->isIssued()) {
                    echo "\n";
                    break;
                }

                $dots = ($dots + 1) % 4;
                printLine("Polling" . str_repeat('.', $dots) . str_repeat(' ', 3 - $dots));

                sleep(2);
            }

            success("Payment received!");
            echo "Minting tokens...\n";

            $newProofs = $this->wallet->mint($quote->quote, $amount);
            $this->proofs = array_merge($this->proofs, $newProofs);

            $amounts = array_map(fn($p) => $p->amount, $newProofs);
            $amountsStr = implode(', ', $amounts);
            $formattedAmount = $this->wallet->formatAmount($amount);

            success("Minted $formattedAmount in " . count($newProofs) . " proofs: [$amountsStr]");
            echo "\nCurrent balance: " . $this->wallet->formatAmount(Wallet::sumProofs($this->proofs)) . "\n";

        } catch (CashuException $e) {
            error("Minting failed: " . $e->getMessage());
        }
    }

    private function meltTokens(): void
    {
        $balance = Wallet::sumProofs($this->proofs);

        if ($balance === 0) {
            error("No tokens to melt. Mint some tokens first.");
            return;
        }

        $formattedBalance = $this->wallet->formatAmount($balance);
        echo "Current balance: $formattedBalance\n";
        $invoice = prompt('Enter Lightning invoice to pay');

        if (empty($invoice)) {
            error("Invoice cannot be empty");
            return;
        }

        echo "\nRequesting melt quote...\n";

        try {
            $quote = $this->wallet->requestMeltQuote($invoice);

            $totalNeeded = $quote->amount + $quote->feeReserve;

            echo "  - Amount: " . $this->wallet->formatAmount($quote->amount) . "\n";
            echo "  - Fee reserve: " . $this->wallet->formatAmount($quote->feeReserve) . "\n";
            echo "  - Total needed: " . $this->wallet->formatAmount($totalNeeded) . "\n";
            echo "\n";

            if ($totalNeeded > $balance) {
                $formattedNeeded = $this->wallet->formatAmount($totalNeeded);
                error("Insufficient balance. Need $formattedNeeded, have $formattedBalance.");
                return;
            }

            $proceed = prompt('Proceed?', 'Y');
            if (strtoupper($proceed) !== 'Y' && strtoupper($proceed) !== 'YES') {
                info("Cancelled");
                return;
            }

            echo "\nMelting tokens...\n";

            // Select proofs for payment
            $selectedProofs = Wallet::selectProofs($this->proofs, $totalNeeded);

            $result = $this->wallet->melt($quote->quote, $selectedProofs);

            if ($result['paid']) {
                success("Payment sent!");
                if (!empty($result['preimage'])) {
                    echo "  - Preimage: {$result['preimage']}\n";
                }

                // Remove used proofs
                $usedSecrets = array_map(fn($p) => $p->secret, $selectedProofs);
                $this->proofs = array_filter(
                    $this->proofs,
                    fn($p) => !in_array($p->secret, $usedSecrets)
                );

                // Add change proofs
                if (!empty($result['change'])) {
                    $changeAmount = Wallet::sumProofs($result['change']);
                    echo "  - Change received: " . $this->wallet->formatAmount($changeAmount) . "\n";
                    $this->proofs = array_merge($this->proofs, $result['change']);
                }

                echo "\nCurrent balance: " . $this->wallet->formatAmount(Wallet::sumProofs($this->proofs)) . "\n";
            } else {
                error("Payment failed or is pending");
            }

        } catch (CashuException $e) {
            error("Melting failed: " . $e->getMessage());
        }
    }

    private function payLightningAddress(): void
    {
        $balance = Wallet::sumProofs($this->proofs);

        if ($balance === 0) {
            error("No tokens to pay with. Mint some tokens first.");
            return;
        }

        $formattedBalance = $this->wallet->formatAmount($balance);
        echo "Current balance: $formattedBalance\n";
        echo "\n";

        $address = prompt('Enter Lightning address (e.g., user@getalby.com)');

        if (empty($address)) {
            error("Lightning address cannot be empty");
            return;
        }

        // Validate address format
        if (!LightningAddress::isValid($address)) {
            error("Invalid Lightning address format. Expected: user@domain");
            return;
        }

        echo "\nResolving Lightning address...\n";

        try {
            $metadata = LightningAddress::resolve($address);

            if ($metadata === null) {
                error("Could not resolve Lightning address. Check that it exists.");
                return;
            }

            $minSats = (int)($metadata['minSendable'] / 1000);
            $maxSats = (int)($metadata['maxSendable'] / 1000);
            $commentAllowed = $metadata['commentAllowed'];

            echo "  - Min: " . $this->wallet->formatAmount($minSats) . "\n";
            echo "  - Max: " . $this->wallet->formatAmount($maxSats) . "\n";
            if ($commentAllowed > 0) {
                echo "  - Comment: up to $commentAllowed chars\n";
            }
            echo "\n";

            // Get amount
            $unitHelper = $this->wallet->getUnitHelper();
            $example = $unitHelper->getExampleAmount();
            $amountStr = prompt("Amount to pay (e.g., $example)", $example);

            try {
                $amount = $this->wallet->parseAmount($amountStr);
            } catch (\InvalidArgumentException $e) {
                error("Invalid amount format");
                return;
            }

            if ($amount <= 0) {
                error("Amount must be positive");
                return;
            }

            // Check amount limits
            if ($amount < $minSats) {
                error("Amount too low. Minimum: " . $this->wallet->formatAmount($minSats));
                return;
            }
            if ($amount > $maxSats) {
                error("Amount too high. Maximum: " . $this->wallet->formatAmount($maxSats));
                return;
            }

            // Get comment if allowed
            $comment = null;
            if ($commentAllowed > 0) {
                $comment = prompt('Add comment (optional)', '');
                if ($comment === '') {
                    $comment = null;
                }
            }

            // Get invoice and quote
            echo "\nGetting invoice from Lightning address...\n";
            $invoice = LightningAddress::getInvoice($address, $amount, $comment);

            echo "Requesting melt quote...\n";
            $quote = $this->wallet->requestMeltQuote($invoice);

            $totalNeeded = $quote->amount + $quote->feeReserve;

            echo "\n";
            echo "  - Amount: " . $this->wallet->formatAmount($quote->amount) . "\n";
            echo "  - Fee reserve: " . $this->wallet->formatAmount($quote->feeReserve) . "\n";
            echo "  - Total needed: " . $this->wallet->formatAmount($totalNeeded) . "\n";
            echo "\n";

            if ($totalNeeded > $balance) {
                $formattedNeeded = $this->wallet->formatAmount($totalNeeded);
                error("Insufficient balance. Need $formattedNeeded, have $formattedBalance.");
                return;
            }

            $proceed = prompt("Pay " . $this->wallet->formatAmount($amount) . " to $address?", 'Y');
            if (strtoupper($proceed) !== 'Y' && strtoupper($proceed) !== 'YES') {
                info("Cancelled");
                return;
            }

            echo "\nPaying...\n";

            // Select proofs for payment
            $selectedProofs = Wallet::selectProofs($this->proofs, $totalNeeded);

            $result = $this->wallet->melt($quote->quote, $selectedProofs);

            if ($result['paid']) {
                success("Payment sent to $address!");
                if (!empty($result['preimage'])) {
                    echo "  - Preimage: {$result['preimage']}\n";
                }

                // Remove used proofs
                $usedSecrets = array_map(fn($p) => $p->secret, $selectedProofs);
                $this->proofs = array_filter(
                    $this->proofs,
                    fn($p) => !in_array($p->secret, $usedSecrets)
                );

                // Add change proofs
                if (!empty($result['change'])) {
                    $changeAmount = Wallet::sumProofs($result['change']);
                    echo "  - Change received: " . $this->wallet->formatAmount($changeAmount) . "\n";
                    $this->proofs = array_merge($this->proofs, $result['change']);
                }

                echo "\nCurrent balance: " . $this->wallet->formatAmount(Wallet::sumProofs($this->proofs)) . "\n";
            } else {
                error("Payment failed or is pending");
            }

        } catch (CashuException $e) {
            error("Payment failed: " . $e->getMessage());
        }
    }

    private function checkBalance(): void
    {
        $balance = Wallet::sumProofs($this->proofs);
        $proofCount = count($this->proofs);
        $formattedBalance = $this->wallet->formatAmount($balance);
        $unitName = strtoupper($this->wallet->getUnit());

        echo "=== Balance ===\n";
        echo "Total: $formattedBalance in $proofCount proofs\n";

        if ($proofCount > 0) {
            echo "\nProof breakdown (smallest units):\n";
            $amounts = [];
            foreach ($this->proofs as $proof) {
                $amounts[] = $proof->amount;
            }
            sort($amounts);
            $amountsStr = implode(', ', $amounts);
            echo "  [$amountsStr]\n";
        }
    }

    private function showToken(): void
    {
        if (empty($this->proofs)) {
            error("No tokens to show. Mint some tokens first.");
            return;
        }

        $balance = Wallet::sumProofs($this->proofs);
        $fee = $this->wallet->calculateFee($this->proofs);
        $available = $balance - $fee;
        $unitHelper = $this->wallet->getUnitHelper();

        echo "Current balance: " . $this->wallet->formatAmount($balance) . "\n";
        if ($fee > 0) {
            echo "Swap fee: " . $this->wallet->formatAmount($fee) . ", available to send: " . $this->wallet->formatAmount($available) . "\n";
        }
        echo "\n";

        $formattedAvailable = $this->wallet->formatAmount($available);
        $amountStr = prompt("Amount to send (leave empty for all $formattedAvailable)", '');

        if ($amountStr === '') {
            $amount = $available;
        } else {
            try {
                $amount = $this->wallet->parseAmount($amountStr);
            } catch (\InvalidArgumentException $e) {
                error("Invalid amount format");
                return;
            }
        }

        if ($amount <= 0) {
            error("Amount must be positive");
            return;
        }

        if ($amount > $available) {
            $formattedRequested = $this->wallet->formatAmount($amount);
            error("Insufficient balance. Have $formattedAvailable available (after " . $this->wallet->formatAmount($fee) . " fee), requested $formattedRequested.");
            return;
        }

        echo "\nSelect format:\n";
        echo "  [1] V4 (cashuB, compact)\n";
        echo "  [2] V3 (cashuA, legacy)\n";
        $formatChoice = prompt('Choice', '1');

        $format = $formatChoice === '2' ? 'v3' : 'v4';

        $memo = prompt('Add memo (optional)', '');
        $memo = $memo !== '' ? $memo : null;

        try {
            $proofsToShow = $this->proofs;

            // If requesting specific amount or there's a fee, we need to swap
            if ($amount < $available || $fee > 0) {
                echo "\nSplitting tokens...\n";
                $split = $this->wallet->split($this->proofs, $amount);
                $proofsToShow = $split['send'];

                // Update our proofs with the kept portion
                $this->proofs = $split['keep'];

                $sendAmounts = array_map(fn($p) => $p->amount, $split['send']);
                sort($sendAmounts);
                $feeMsg = $split['fee'] > 0 ? " (fee: " . $this->wallet->formatAmount($split['fee']) . ")" : "";
                $formattedKeep = $this->wallet->formatAmount(Wallet::sumProofs($split['keep']));
                info("Split into: send [" . implode(', ', $sendAmounts) . "], keep $formattedKeep" . $feeMsg);
            } else {
                // Showing all tokens with no fee - clear the balance
                $this->proofs = [];
            }

            $token = $this->wallet->serializeToken($proofsToShow, $format, $memo);
            $formattedAmount = $this->wallet->formatAmount($amount);

            echo "\n";
            echo "=== Token String ($formattedAmount) ===\n";
            echo "\033[36m$token\033[0m\n";
            echo "\n";

            info("This token contains $formattedAmount");
            echo "Remaining balance: " . $this->wallet->formatAmount(Wallet::sumProofs($this->proofs)) . "\n";

        } catch (CashuException $e) {
            error("Failed to create token: " . $e->getMessage());
        }
    }

    private function receiveToken(): void
    {
        echo "Enter token or file path (if paste doesn't work, save to file first):\n";

        $input = prompt('Token or path');

        if (empty($input)) {
            error("Input cannot be empty");
            return;
        }

        $tokenString = $input;

        // Check if it looks like a file path
        $looksLikePath = str_starts_with($input, '/') ||
                         str_starts_with($input, './') ||
                         str_starts_with($input, '~/') ||
                         (strlen($input) < 100 && !str_starts_with($input, 'cashu'));

        if ($looksLikePath && !str_starts_with($input, 'cashu')) {
            $filePath = $input;
            // Expand ~ to home directory
            if (str_starts_with($filePath, '~/')) {
                $filePath = ($_SERVER['HOME'] ?? getenv('HOME')) . substr($filePath, 1);
            }
            if (file_exists($filePath)) {
                $tokenString = trim(file_get_contents($filePath));
                if (empty($tokenString)) {
                    error("File is empty");
                    return;
                }
                info("Read " . strlen($tokenString) . " chars from: $filePath");
            } elseif (!str_starts_with($input, 'cashu')) {
                error("File not found: $filePath");
                return;
            }
        }

        if (empty($tokenString)) {
            error("Token string cannot be empty");
            return;
        }

        echo "\nProcessing token (" . strlen($tokenString) . " chars)...\n";

        try {
            // Deserialize first to check
            $token = $this->wallet->deserializeToken($tokenString);
            $amount = $token->getAmount();
            $proofCount = count($token->proofs);
            $fee = $this->wallet->calculateFee($token->proofs);
            $receiveAmount = $amount - $fee;

            // Format token amount using token's unit
            $tokenUnit = strtoupper($token->unit);
            $formattedTokenAmount = Wallet::formatAmountForUnit($amount, $token->unit);
            $walletUnit = strtoupper($this->wallet->getUnit());

            echo "  - Token amount: $formattedTokenAmount\n";
            echo "  - Unit: $tokenUnit\n";
            echo "  - Proofs: $proofCount\n";
            echo "  - Mint: {$token->mint}\n";
            if ($fee > 0) {
                echo "  - Swap fee: " . Wallet::formatAmountForUnit($fee, $token->unit) . "\n";
                echo "  - You will receive: " . Wallet::formatAmountForUnit($receiveAmount, $token->unit) . "\n";
            }
            echo "\n";

            // Check if token is from this mint
            if (rtrim($token->mint, '/') !== $this->wallet->getMintUrl()) {
                error("Token is from a different mint: {$token->mint}");
                info("This wallet is connected to: " . $this->wallet->getMintUrl());
                return;
            }

            // Check if token unit matches wallet unit
            if ($token->unit !== $this->wallet->getUnit()) {
                error("Token is $tokenUnit but wallet is $walletUnit");
                info("Create a separate wallet for $tokenUnit tokens");
                return;
            }

            if ($receiveAmount <= 0) {
                $formattedAmount = $this->wallet->formatAmount($amount);
                $formattedFee = $this->wallet->formatAmount($fee);
                error("Token amount ($formattedAmount) is less than or equal to fee ($formattedFee)");
                return;
            }

            echo "Swapping for fresh proofs...\n";

            $newProofs = $this->wallet->receive($tokenString);
            $this->proofs = array_merge($this->proofs, $newProofs);

            $actualReceived = Wallet::sumProofs($newProofs);
            $formattedReceived = $this->wallet->formatAmount($actualReceived);
            success("Received $formattedReceived in " . count($newProofs) . " proofs");
            echo "\nCurrent balance: " . $this->wallet->formatAmount(Wallet::sumProofs($this->proofs)) . "\n";

        } catch (CashuException $e) {
            error("Failed to receive token: " . $e->getMessage());
        }
    }

    private function checkProofState(): void
    {
        if (empty($this->proofs)) {
            error("No proofs to check. Mint some tokens first.");
            return;
        }

        echo "Checking proof states...\n";

        try {
            $states = $this->wallet->checkProofState($this->proofs);

            echo "\n=== Proof States ===\n";

            $spentIndices = [];
            $spentAmount = 0;

            foreach ($states as $i => $state) {
                $stateStr = $state['state'] ?? 'UNKNOWN';
                $amount = isset($this->proofs[$i]) ? $this->proofs[$i]->amount : 0;
                $formattedAmount = $this->wallet->formatAmount($amount);

                $color = match ($stateStr) {
                    ProofState::UNSPENT => "\033[32m", // green
                    ProofState::PENDING => "\033[33m", // yellow
                    ProofState::SPENT => "\033[31m",   // red
                    default => "\033[0m",
                };

                echo "  Proof " . ($i + 1) . " ($formattedAmount): {$color}$stateStr\033[0m\n";

                // Track spent proofs
                if ($stateStr === ProofState::SPENT) {
                    $spentIndices[] = $i;
                    $spentAmount += $amount;
                }
            }

            // Remove spent proofs from balance
            if (!empty($spentIndices)) {
                echo "\n";
                $this->proofs = array_values(array_filter(
                    $this->proofs,
                    fn($proof, $index) => !in_array($index, $spentIndices),
                    ARRAY_FILTER_USE_BOTH
                ));

                $formattedSpent = $this->wallet->formatAmount($spentAmount);
                info("Removed " . count($spentIndices) . " spent proof(s) ($formattedSpent) from balance");
                echo "New balance: " . $this->wallet->formatAmount(Wallet::sumProofs($this->proofs)) . "\n";
            }

        } catch (CashuException $e) {
            error("Failed to check proof state: " . $e->getMessage());
        }
    }

    private function manageSeedPhrase(): void
    {
        echo "=== Seed Phrase ===\n";
        echo "\n";
        echo "[1] Show current seed phrase\n";
        echo "[2] Import different seed (for recovery)\n";
        echo "[3] Back to main menu\n";
        echo "\n";

        $choice = prompt('Choice', '1');
        echo "\n";

        switch ($choice) {
            case '1':
                $this->showSeedPhrase();
                break;
            case '2':
                $this->importSeed();
                break;
            case '3':
                return;
            default:
                error("Invalid choice");
        }
    }

    private function showSeedPhrase(): void
    {
        $mnemonic = $this->wallet->getMnemonic();
        if ($mnemonic === null) {
            error("No seed phrase available");
            return;
        }

        echo "\033[33m=== WARNING ===\033[0m\n";
        echo "Anyone with this phrase can spend your tokens!\n";
        echo "Make sure no one is watching your screen.\n";
        echo "\n";

        $confirm = prompt('Show seed phrase?', 'N');
        if (strtoupper($confirm) !== 'Y' && strtoupper($confirm) !== 'YES') {
            info("Cancelled");
            return;
        }

        echo "\n";
        echo "Your seed phrase (WRITE THIS DOWN!):\n";
        echo "\n";

        $words = explode(' ', $mnemonic);
        for ($i = 0; $i < count($words); $i++) {
            $num = str_pad((string)($i + 1), 2, ' ', STR_PAD_LEFT);
            echo "  $num. {$words[$i]}\n";
        }

        echo "\n";
        info("Store this securely! You can restore your wallet with these words.");
    }

    private function importSeed(): void
    {
        echo "\033[33mWARNING: This will replace your current seed!\033[0m\n";
        echo "Only do this if you want to recover a wallet from a different seed.\n";
        echo "\n";
        $confirm = prompt('Continue?', 'N');
        if (strtoupper($confirm) !== 'Y' && strtoupper($confirm) !== 'YES') {
            info("Cancelled");
            return;
        }
        echo "\n";

        echo "Enter your 12-word seed phrase (space-separated):\n";
        $mnemonic = prompt('Seed phrase');

        if (empty($mnemonic)) {
            error("Seed phrase cannot be empty");
            return;
        }

        // Validate word count
        $words = preg_split('/\s+/', trim($mnemonic));
        if (!in_array(count($words), [12, 15, 18, 21, 24])) {
            error("Invalid word count. Expected 12, 15, 18, 21, or 24 words.");
            return;
        }

        try {
            $this->wallet->initFromMnemonic($mnemonic);
            success("Seed phrase imported successfully!");
            info("You can now use 'Restore wallet from seed' to recover tokens.");
        } catch (CashuException $e) {
            error("Failed to import seed: " . $e->getMessage());
        }
    }

    private function restoreWallet(): void
    {
        echo "=== Restore from Seed Phrase ===\n";
        echo "\n";
        echo "Enter a seed phrase to scan the mint for tokens.\n";
        echo "This will replace your current seed and add any found tokens to your balance.\n";
        echo "\n";

        echo "Enter your 12-word seed phrase (space-separated):\n";
        $mnemonic = prompt('Seed phrase');

        if (empty($mnemonic)) {
            error("Seed phrase cannot be empty");
            return;
        }

        // Validate word count
        $words = preg_split('/\s+/', trim($mnemonic));
        if (!in_array(count($words), [12, 15, 18, 21, 24])) {
            error("Invalid word count. Expected 12, 15, 18, 21, or 24 words.");
            return;
        }

        try {
            $this->wallet->initFromMnemonic($mnemonic);
            success("Seed phrase imported");
        } catch (CashuException $e) {
            error("Invalid seed phrase: " . $e->getMessage());
            return;
        }

        echo "\n";
        info("Scanning mint for tokens...");
        echo "\n";

        try {
            // Restore ALL units to prevent proof reuse from melt fee change
            $result = $this->wallet->restore(
                batchSize: 25,
                emptyBatches: 3,
                progressCallback: function ($keysetId, $counter, $found, $unit) {
                    $shortId = substr($keysetId, 0, 8) . '...';
                    $range = $counter . '-' . ($counter + 24);
                    if ($found > 0) {
                        echo "  [$unit] Keyset $shortId: counters $range - \033[32mfound $found proofs\033[0m\n";
                    } else {
                        printLine("  [$unit] Keyset $shortId: counters $range - scanning...");
                    }
                },
                allUnits: true  // Restore all units (default) - prevents proof reuse from melt change
            );

            echo "\r\033[K"; // Clear the last line

            // Show results by unit
            $byUnit = $result['byUnit'] ?? [];
            if (empty($byUnit)) {
                info("No tokens found for this seed.");
            } else {
                echo "\n";
                success("Restore complete! Found tokens in " . count($byUnit) . " unit(s):");
                echo "\n";

                foreach ($byUnit as $unit => $unitData) {
                    $unitProofs = $unitData['proofs'];
                    $unitAmount = Wallet::sumProofs($unitProofs);
                    $formatted = Wallet::formatAmountForUnit($unitAmount, $unit);
                    echo "  $unit: " . count($unitProofs) . " proofs ($formatted)\n";
                }

                // Only add proofs for this wallet's unit to local balance
                $walletUnit = $this->wallet->getUnit();
                if (isset($byUnit[$walletUnit])) {
                    $this->proofs = array_merge($this->proofs, $byUnit[$walletUnit]['proofs']);
                    echo "\nAdded " . $walletUnit . " proofs to current wallet balance.\n";
                }

                echo "Current balance: " . $this->wallet->formatAmount(Wallet::sumProofs($this->proofs)) . "\n";

                // Warn about other units if present
                $otherUnits = array_keys(array_diff_key($byUnit, [$walletUnit => true]));
                if (!empty($otherUnits)) {
                    echo "\n\033[33mNote: Found tokens in other units (" . implode(', ', $otherUnits) . ").\n";
                    echo "These are stored but not shown in this wallet's balance.\n";
                    echo "Create a wallet with that unit to access them.\033[0m\n";
                }
            }

        } catch (CashuException $e) {
            error("Restore failed: " . $e->getMessage());
        }
    }
}

// ============================================================================
// MAIN ENTRY POINT
// ============================================================================

// Handle Ctrl+C gracefully
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, function () {
        echo "\n\nInterrupted. Goodbye!\n";
        exit(0);
    });
}

// Run the tester
$tester = new CashuWalletTester();
$tester->run();
