<?php
/**
 * Mercado Pago Checkout Pro para FOSSBilling
 * Versão Corrigida - Janeiro 2026
 * FIX: Previne processamento duplicado e garante ativação do serviço
 * FEATURE: Sistema de taxas configurável
 */

class Payment_Adapter_MercadoPago extends Payment_AdapterAbstract implements FOSSBilling\InjectionAwareInterface
{
    protected ?Pimple\Container $di = null;

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    public function __construct(private $config)
    {
        if (empty($this->config['access_token'])) {
            throw new Payment_Exception('Access Token não configurado');
        }
    }

    public static function getConfig()
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => 'Mercado Pago Checkout Pro com webhooks automáticos',
			'logo' => [
                'logo' => 'mercadopago.png',
                'height' => '30px',
                'width' => '90px',
            ],
            'form' => [
                'access_token' => [
                    'text',
                    [
                        'label' => 'Access Token',
                        'description' => 'Cole aqui seu token do Mercado Pago',
                        'required' => true,
                    ],
                ],
                'secret_key' => [
                    'text',
                    [
                        'label' => 'Secret Key (Opcional)',
                        'description' => 'Para validar webhooks. Recomendado em produção.',
                        'required' => false,
                    ],
                ],
				'logo_url' => [
                    'text',
                    [
                        'label' => 'URL do Logo (Opcional)',
                        'description' => 'URL da imagem para exibir no botão (ex: https://site.com/logo.png)',
                        'required' => false,
                    ],
                ],
                'enable_fees' => [
                    'radio',
                    [
                        'label' => 'Ativar Taxa de Processamento',
                        'description' => 'Adicionar taxa fixa ao valor da fatura',
                        'multiOptions' => [
                            '1' => 'Sim',
                            '0' => 'Não',
                        ],
                        'value' => '0',
                    ],
                ],
                'fee_percentage' => [
                    'text',
                    [
                        'label' => 'Taxa Percentual (%)',
                        'description' => 'Percentual da taxa a ser adicionado (ex: 5.5 para 5,5%). Usar ponto como separador decimal.',
                        'required' => false,
                        'value' => '0.00',
                    ],
                ],
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        try {
            $invoice = $api_admin->invoice_get(['id' => $invoice_id]);
            $preference = $this->createPreference($invoice);

            if (!$preference) {
                return '<div class="alert alert-danger">Erro ao criar pagamento. Contate o suporte.</div>';
            }

            $paymentUrl = $preference['init_point'];
			
			$logoUrl = !empty($this->config['logo_url']) ? $this->config['logo_url'] : null;
            
            if (!$logoUrl) {
                 // Use local asset by default
                 $logoUrl = $this->di['tools']->url('data/assets/gateways/mercadopago.png');
            }
            
            $btnContent = "<img src='{$logoUrl}' alt='Mercado Pago' style='max-height:24px; vertical-align:middle; margin-right:10px;'> Pagar com Mercado Pago";

            // Exibe informação sobre taxa se estiver ativa
            $feeNotice = '';
            if ($this->isFeesEnabled()) {
                $invoiceTotal = round((float)$invoice['total'], 2);
                $feeAmount = $this->calculateFeeAmount($invoiceTotal);
                $percentage = $this->getFeePercentage();
                $feeFormatted = number_format($feeAmount, 2, ',', '.');
                $percentageFormatted = number_format($percentage, 2, ',', '.');
                $feeNotice = "<p style='margin-top:10px; color:#666; font-size:14px;'>
                    <em>Taxa de processamento ({$percentageFormatted}%): R$ {$feeFormatted}</em>
                </p>";
            }

            return "
            <div style='text-align:center; padding:30px;'>
                <a href='{$paymentUrl}' class='btn btn-primary btn-lg' style='background:#009EE3; padding:18px 50px; font-size:20px;'>
                    {$btnContent}
                </a>
                {$feeNotice}
                <p style='margin-top:15px; color:#666;'>
                    Redirecionando em <strong id='countdown'>3</strong> segundos...
                </p>
                <script>
                    let s = 3;
                    const redirect = () => window.location.href = '{$paymentUrl}';
                    setTimeout(redirect, 3000);
                    setInterval(() => {
                        const el = document.getElementById('countdown');
                        if (el && s > 0) el.textContent = --s;
                    }, 1000);
                </script>
            </div>";
        } catch (Exception $e) {
            error_log('[MercadoPago] Erro: ' . $e->getMessage());
            return '<div class="alert alert-danger">Erro interno. Tente novamente.</div>';
        }
    }

    /**
     * Verifica se as taxas estão habilitadas
     */
    private function isFeesEnabled(): bool
    {
        return !empty($this->config['enable_fees']) && $this->config['enable_fees'] === '1';
    }

    /**
     * Obtém o percentual da taxa configurada
     */
    private function getFeePercentage(): float
    {
        if (!$this->isFeesEnabled()) {
            return 0.0;
        }

        $percentage = $this->config['fee_percentage'] ?? '0.00';
        
        // Remove possíveis vírgulas e converte para float
        $percentage = str_replace(',', '.', $percentage);
        $percentage = (float) $percentage;
        
        return max(0.0, min(100.0, $percentage)); // Entre 0 e 100%
    }

    /**
     * Calcula o valor total com taxa percentual
     */
    private function calculateTotalWithFee(float $invoiceTotal): float
    {
        $percentage = $this->getFeePercentage();
        $feeAmount = ($invoiceTotal * $percentage) / 100;
        $total = $invoiceTotal + $feeAmount;
        
        return round($total, 2);
    }

    /**
     * Calcula o valor da taxa em reais
     */
    private function calculateFeeAmount(float $invoiceTotal): float
    {
        $percentage = $this->getFeePercentage();
        $feeAmount = ($invoiceTotal * $percentage) / 100;
        
        return round($feeAmount, 2);
    }

    private function createPreference($invoice): ?array
    {
        $invoiceId = $invoice['id'];
        $invoiceTotal = round((float)$invoice['total'], 2);
        
        // Aplica taxa se habilitada
        $total = $this->calculateTotalWithFee($invoiceTotal);

        if ($total < 0.50) {
            error_log('[MercadoPago] Valor muito baixo: ' . $total);
            return null;
        }

        $tools = $this->di['tools'];
        $baseUrl = $tools->url('');
        $webhookUrl = rtrim($baseUrl, '/') . '/ipn.php?gateway_id=' . (int) $invoice['gateway_id'] . '&invoice_id=' . (int) $invoiceId;
        $itemTitle = "Fatura #{$invoice['nr']} [INV_{$invoiceId}]";
        if ($this->isFeesEnabled() && $this->getFeePercentage() > 0) {
            $percentage = $this->getFeePercentage();
            $feeAmount = $this->calculateFeeAmount($invoiceTotal);
            $percentageFormatted = number_format($percentage, 2, ',', '.');
            $feeFormatted = number_format($feeAmount, 2, ',', '.');
            $itemTitle .= " + Taxa {$percentageFormatted}% (R$ {$feeFormatted})";
        }

        $payload = [
            'items' => [[
                'title' => $itemTitle,
                'quantity' => 1,
                'currency_id' => 'BRL',
                'unit_price' => $total,
            ]],
            'payer' => [
                'email' => $this->getEmail($invoice),
            ],
            'back_urls' => [
                'success' => $this->di['url']->link('invoice', ['id' => $invoice['hash']]),
                'pending' => $this->di['url']->link('invoice', ['id' => $invoice['hash']]),
                'failure' => $this->di['url']->link('invoice', ['id' => $invoice['hash']]),
            ],
            'auto_return' => 'approved',
            'notification_url' => $webhookUrl,
            'external_reference' => "INV_{$invoiceId}",
            'metadata' => [
                'invoice_id' => (string) $invoiceId,
                'gateway_id' => (string) $invoice['gateway_id'],
            ],
        ];

        // Log para debug
        if ($this->isFeesEnabled()) {
            $percentage = $this->getFeePercentage();
            $feeAmount = $this->calculateFeeAmount($invoiceTotal);
            error_log("[MercadoPago] 💰 Taxa aplicada - Original: R$ {$invoiceTotal} | Taxa: {$percentage}% (R$ {$feeAmount}) | Total: R$ {$total}");
        }

        // Adiciona expiration date to checkout if configured
        $tempoExpiracao = (int)($this->config['tempo_expiracao'] ?? 30);
        if ($tempoExpiracao > 0) {
            $expiration = (new \DateTime())->add(new \DateInterval("PT{$tempoExpiracao}M"))->format('Y-m-d\TH:i:sP');
            $payload['date_of_expiration'] = $expiration; // MP expects ISO8601-ish format
            error_log("[MercadoPago] ⏱ Expiração configurada: {$expiration}");
        }

        $ch = curl_init('https://api.mercadopago.com/checkout/preferences');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->config['access_token'],
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $result = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 201) {
            error_log("[MercadoPago] ❌ Erro API ({$code}): {$result}");
            return null;
        }

        return json_decode($result, true);
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        $webhook = $data['post'] ?? [];
        $type = $webhook['type'] ?? $webhook['action'] ?? 'DESCONHECIDO';

        // Filtra apenas pagamentos
        if (strpos($type, 'payment') === false) {
            return;
        }

        $paymentId = $webhook['data']['id'] ?? null;
        if (!$paymentId) {
            error_log('[MercadoPago] ❌ Sem payment ID no webhook');
            return;
        }

        // Ignora webhooks de teste
        if (in_array($paymentId, ['123456', '12345678', 1234567890])) {
            return;
        }

        error_log('[MercadoPago] 📨 Webhook recebido - Payment ID: ' . $paymentId);

        // 🔒 LOCK DE PROCESSAMENTO - Previne duplicação
        $lockKey = "mp_payment_{$paymentId}";
        if ($this->isLocked($lockKey)) {
            return;
        }
        $this->setLock($lockKey);

        try {
            // Busca detalhes do pagamento
            $payment = $this->getPayment($paymentId);
            if (!$payment) {
                error_log('[MercadoPago] ❌ Não foi possível buscar dados do pagamento');
                return;
            }

            error_log('[MercadoPago] 💰 Status do pagamento: ' . $payment['status']);
            
            // Log completo para debug
            error_log('[MercadoPago] 🔍 Payment completo: ' . json_encode($payment));
            
            // Extrai invoice_id de múltiplas fontes (suporta POS/Maquininha)
            $invoiceId = null;

            // 1. external_reference: INV_123
            $extRef = $payment['external_reference'] ?? '';
            if (preg_match('/INV_?0*(\d+)/i', $extRef, $m)) {
                $invoiceId = (int)$m[1];
                error_log('[MercadoPago] Invoice via external_reference: ' . $invoiceId);
            }

            // 2. metadata.invoice_id (enviado na criação da preference)
            if (!$invoiceId && !empty($payment['metadata']['invoice_id'])) {
                $invoiceId = (int)$payment['metadata']['invoice_id'];
                error_log('[MercadoPago] Invoice via metadata: ' . $invoiceId);
            }

            // 3. description ou statement_descriptor contendo INV_
            if (!$invoiceId) {
                $desc = ($payment['description'] ?? '') . ' ' . ($payment['statement_descriptor'] ?? '');
                if (preg_match('/INV_?0*(\d+)/i', $desc, $m)) {
                    $invoiceId = (int)$m[1];
                    error_log('[MercadoPago] Invoice via description: ' . $invoiceId);
                }
            }

            // 4. Fallback por valor exato (para pagamentos POS/Maquininha)
            if (!$invoiceId) {
                $amount = isset($payment['transaction_amount']) ? round((float)$payment['transaction_amount'], 2) : null;
                if ($amount && $amount > 0) {
                    $rows = $this->di['db']->getAll(
                        'SELECT i.id
                         FROM invoice i
                         LEFT JOIN (
                             SELECT invoice_id, SUM(price * quantity) AS subtotal
                             FROM invoice_item
                             GROUP BY invoice_id
                         ) items ON items.invoice_id = i.id
                         WHERE i.gateway_id = :gw
                           AND i.status = :st
                           AND ROUND(COALESCE(items.subtotal, 0), 2) = :amount
                         ORDER BY i.id DESC
                         LIMIT 2',
                        [':gw' => (int)$gateway_id, ':st' => \Model_Invoice::STATUS_UNPAID, ':amount' => $amount]
                    );

                    if (count($rows) === 1) {
                        $invoiceId = (int)$rows[0]['id'];
                        error_log("[MercadoPago] Invoice via fallback por valor (R$ {$amount}): {$invoiceId}");
                    } elseif (count($rows) > 1) {
                        error_log("[MercadoPago] ⚠️ Fallback ambíguo: múltiplas faturas com valor R$ {$amount}");
                    }
                }
            }

            if (!$invoiceId) {
                $operationType = $payment['operation_type'] ?? 'unknown';
                $pointType = $payment['point_of_interaction']['type'] ?? 'unknown';
                error_log("[MercadoPago] ❌ Não foi possível identificar a fatura. Ref: '{$extRef}', Tipo: {$operationType}, Point: {$pointType}");
                return;
            }

            // Verifica se já foi processado
            try {
                $existing = $api_admin->invoice_transaction_get(['txn_id' => (string)$paymentId]);
                error_log('[MercadoPago] ✅ Transação já processada anteriormente');
                return;
            } catch (Exception $e) {
                // Não existe, continuar
            }

            // Só processa se aprovado
            if ($payment['status'] !== 'approved') {
                // Se for rejeitado ou cancelado, não cria transação pendente (evita poluição visual)
				if (in_array($payment['status'], ['rejected', 'cancelled'])) {
					return;
				}

                // Registra como pendente
                try {
                    $api_admin->invoice_transaction_create([
                        'invoice_id' => $invoiceId,
                        'gateway_id' => $gateway_id,
                        'txn_id' => (string)$paymentId,
                        'amount' => $payment['transaction_amount'],
                        'currency' => $payment['currency_id'],
                        'status' => 'pending',
                        'type' => 'payment',
                    ]);
                    error_log('[MercadoPago] 📝 Transação pendente registrada');
                } catch (Exception $e) {
                    error_log('[MercadoPago] ⚠️ Erro ao registrar pendente: ' . $e->getMessage());
                }
                
                return;
            }

            $invoice = $api_admin->invoice_get(['id' => $invoiceId]);
            
            if ($invoice['status'] === 'paid') {
                error_log('[MercadoPago] ℹ️ Fatura já está marcada como paga');
                return;
            }

            // 1. Registra transação (com o valor RECEBIDO do Mercado Pago, incluindo taxa)
            $txn = $api_admin->invoice_transaction_create([
                'invoice_id' => $invoiceId,
                'gateway_id' => $gateway_id,
                'txn_id' => (string)$paymentId,
                'amount' => $payment['transaction_amount'],
                'currency' => $payment['currency_id'],
                'status' => 'processed',
                'type' => 'payment',
            ]);
            error_log('[MercadoPago] ✅ Transação criada: ID #' . $txn);

            // Log de informação sobre taxa
            if ($this->isFeesEnabled()) {
                $percentage = $this->getFeePercentage();
                $invoiceTotal = round((float)$invoice['total'], 2);
                $feeAmount = $this->calculateFeeAmount($invoiceTotal);
                error_log("[MercadoPago] 💵 Taxa configurada: {$percentage}% (R$ {$feeAmount}) | Valor pago: R$ {$payment['transaction_amount']}");
            }

            // 2. Marca fatura como paga (ISSO ATIVA O SERVIÇO AUTOMATICAMENTE)
            error_log('[MercadoPago] 💵 Marcando fatura como paga...');

            // Verifica se o gateway da fatura ainda existe
            $gatewayCheck = $this->di['db']->load('PayGateway', $invoice['gateway_id']);
            if (!$gatewayCheck) {
                error_log("[MercadoPago] ⚠️ Gateway ID {$invoice['gateway_id']} não encontrado para Invoice #{$invoiceId}. Verifique se o gateway foi deletado ou se há conflito de e‑mail (ex: comprador usa mesmo e‑mail da conta Mercado Livre).");
            }

            $result = $api_admin->invoice_mark_as_paid([
                'id' => $invoiceId,
                'note' => "Pagamento aprovado - Mercado Pago ID: {$paymentId}",
                'execute' => true  // 🔥 FORÇA EXECUÇÃO DOS HOOKS
            ]);
            error_log('[MercadoPago] 📊 Resultado: ' . json_encode($result));

        } catch (Exception $e) {
            error_log('[MercadoPago] ==========================================');
            error_log('[MercadoPago] ❌❌❌ ERRO CRÍTICO ❌❌❌');
            error_log('[MercadoPago] ==========================================');
            error_log('[MercadoPago] Invoice ID: ' . ($invoiceId ?? 'N/A'));
            error_log('[MercadoPago] Payment ID: ' . $paymentId);
            error_log('[MercadoPago] Mensagem: ' . $e->getMessage());
            error_log('[MercadoPago] Arquivo: ' . $e->getFile() . ':' . $e->getLine());
            error_log('[MercadoPago] Stack trace:');
            error_log($e->getTraceAsString());
            error_log('[MercadoPago] ==========================================');
        } finally {
            $this->releaseLock($lockKey);
        }
    }

    /**
     * Sistema de Lock atômico usando flock()
     * @var array<string, resource>
     */
    private array $lockHandles = [];

    private function isLocked(string $key): bool
    {
        $lockFile = sys_get_temp_dir() . '/' . md5($key) . '.lock';
        $handle = @fopen($lockFile, 'c');
        if (!$handle) {
            return true;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return true;
        }

        $this->lockHandles[$key] = $handle;
        fwrite($handle, (string)time());
        return false;
    }

    private function setLock(string $key): void
    {
        // Lock already acquired in isLocked()
    }

    private function releaseLock(string $key): void
    {
        if (isset($this->lockHandles[$key])) {
            flock($this->lockHandles[$key], LOCK_UN);
            fclose($this->lockHandles[$key]);
            unset($this->lockHandles[$key]);
        }
        $lockFile = sys_get_temp_dir() . '/' . md5($key) . '.lock';
        @unlink($lockFile);
    }

    private function getPayment($paymentId): ?array
    {
        $ch = curl_init("https://api.mercadopago.com/v1/payments/{$paymentId}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->config['access_token'],
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        $result = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            error_log("[MercadoPago] ❌ Erro ao buscar pagamento (HTTP {$code})");
            error_log("[MercadoPago] Resposta: {$result}");
            return null;
        }

        return json_decode($result, true);
    }

    private function getEmail($invoice): string
    {
        $email = $invoice['buyer']['email'] 
            ?? $invoice['client']['email'] 
            ?? null;
            
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
        
        try {
            $sysEmail = $this->di['mod_service']('system')->getParamValue('company_email');
            if ($sysEmail && filter_var($sysEmail, FILTER_VALIDATE_EMAIL)) {
                return $sysEmail;
            }
        } catch (Exception $e) {}
        
        return 'noreply@localhost';
    }
}