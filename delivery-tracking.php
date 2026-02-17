<?php
/**
 * Sistema de Rastreamento de Entregas via Evolution API
 *
 * Monitora se os vendedores receberam as mensagens enviadas pela Evolution API.
 * Se um vendedor não receber confirmação de entrega em 2 horas, é inativado automaticamente.
 *
 * Fluxo:
 * 1. Formulário enviado → webhook vai pro n8n → Evolution API envia mensagem
 * 2. Evolution API envia webhook de status (DELIVERY_ACK) → este endpoint recebe
 * 3. WP-Cron verifica entregas não confirmadas a cada 30 min
 * 4. Se passou 2h sem confirmação → vendedor é desativado
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hapvida_Delivery_Tracking
{
    /** Option onde ficam as entregas pendentes */
    const OPTION_PENDING = 'hapvida_pending_deliveries';

    /** Option com log de inativações automáticas */
    const OPTION_DEACTIVATION_LOG = 'hapvida_auto_deactivation_log';

    /** Option com as configurações */
    const OPTION_SETTINGS = 'formulario_hapvida_settings';

    /** Option com os vendedores */
    const OPTION_VENDORS = 'formulario_hapvida_vendedores';

    /** Timeout em segundos (2 horas) */
    const DELIVERY_TIMEOUT = 7200;

    /** Hook do cron */
    const CRON_HOOK = 'hapvida_check_delivery_timeout';

    /** Intervalo do cron (30 min) */
    const CRON_INTERVAL = 'hapvida_thirty_minutes';

    public function __construct()
    {
        // Registra endpoint REST para receber webhook da Evolution API
        add_action('rest_api_init', array($this, 'register_endpoints'));

        // Registra cron para verificar entregas expiradas
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
        add_action(self::CRON_HOOK, array($this, 'check_expired_deliveries'));

        // Agenda cron se ainda não estiver agendado
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), self::CRON_INTERVAL, self::CRON_HOOK);
        }
    }

    /**
     * Adiciona intervalo de 30 minutos ao cron (se não existir)
     */
    public function add_cron_interval($schedules)
    {
        if (!isset($schedules[self::CRON_INTERVAL])) {
            $schedules[self::CRON_INTERVAL] = array(
                'interval' => 1800,
                'display' => 'A cada 30 minutos'
            );
        }
        return $schedules;
    }

    /**
     * Registra endpoints REST
     */
    public function register_endpoints()
    {
        // Endpoint para receber webhook da Evolution API
        register_rest_route('formulario-hapvida/v1', '/evolution-webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_evolution_webhook'),
            'permission_callback' => '__return_true'
        ));

        // Rota com evento no path (quando "Webhook by Events" está ativado na Evolution API)
        // A Evolution API envia para: /evolution-webhook/MESSAGES_UPDATE
        register_rest_route('formulario-hapvida/v1', '/evolution-webhook/(?P<event>[a-zA-Z0-9_-]+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_evolution_webhook'),
            'permission_callback' => '__return_true'
        ));

        // Endpoint para verificar status (debug/admin)
        register_rest_route('formulario-hapvida/v1', '/delivery-status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_delivery_status'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));
    }

    // =========================================================================
    // REGISTRAR ENTREGA PENDENTE (chamado quando webhook é enviado ao n8n)
    // =========================================================================

    /**
     * Registra que uma mensagem foi enviada para um vendedor
     * Deve ser chamado após o envio do webhook ao n8n
     *
     * @param array $vendedor Dados do vendedor (nome, telefone, grupo)
     * @param string $lead_id ID do lead
     */
    public function register_pending_delivery($vendedor, $lead_id)
    {
        $pending = get_option(self::OPTION_PENDING, array());

        // Normaliza o telefone para usar como chave
        $phone = $this->normalize_phone($vendedor['telefone']);

        $pending[] = array(
            'lead_id' => $lead_id,
            'vendedor_nome' => $vendedor['nome'],
            'vendedor_telefone' => $phone,
            'vendedor_telefone_original' => $vendedor['telefone'],
            'grupo' => isset($vendedor['grupo']) ? $vendedor['grupo'] : 'drv',
            'enviado_em' => current_time('mysql'),
            'enviado_timestamp' => time(),
            'status' => 'pendente', // pendente | entregue | expirado
            'confirmado_em' => null
        );

        update_option(self::OPTION_PENDING, $pending);

        error_log("HAPVIDA DELIVERY: Entrega registrada - Lead {$lead_id} para {$vendedor['nome']} ({$phone})");
    }

    // =========================================================================
    // RECEBER WEBHOOK DA EVOLUTION API
    // =========================================================================

    /**
     * Processa webhook recebido da Evolution API (ou n8n)
     *
     * A Evolution API envia webhooks com status da mensagem:
     * - PENDING: mensagem pendente
     * - SERVER_ACK: servidor recebeu
     * - DELIVERY_ACK: entregue ao dispositivo
     * - READ: lida pelo destinatário
     * - PLAYED: áudio/vídeo reproduzido
     *
     * Payload esperado da Evolution API (messages.update):
     * {
     *   "event": "messages.update",
     *   "instance": "nome_instancia",
     *   "data": {
     *     "key": {
     *       "remoteJid": "5511999999999@s.whatsapp.net",
     *       "fromMe": true,
     *       "id": "message_id"
     *     },
     *     "update": {
     *       "status": 3  // 2=SERVER_ACK, 3=DELIVERY_ACK, 4=READ
     *     }
     *   }
     * }
     *
     * Payload alternativo (enviado pelo n8n):
     * {
     *   "telefone": "5511999999999",
     *   "status": "delivered" | "read",
     *   "lead_id": "P3-00001"  (opcional)
     * }
     */
    public function handle_evolution_webhook($request)
    {
        $body = $request->get_json_params();

        if (empty($body)) {
            $body = $request->get_body_params();
        }

        if (empty($body)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Payload vazio'
            ), 400);
        }

        error_log("HAPVIDA DELIVERY: Webhook recebido - " . json_encode($body));

        // Tenta extrair telefone e status do payload
        $phone = null;
        $status = null;
        $lead_id = null;

        // Formato Evolution API (messages.update)
        if (isset($body['event']) && $body['event'] === 'messages.update') {
            $data = isset($body['data']) ? $body['data'] : array();
            $key = isset($data['key']) ? $data['key'] : array();
            $update = isset($data['update']) ? $data['update'] : array();

            if (isset($key['remoteJid'])) {
                // Remove @s.whatsapp.net e extrai apenas o número
                $phone = preg_replace('/@.*$/', '', $key['remoteJid']);
            }

            // Status numérico da Evolution API
            $status_code = isset($update['status']) ? intval($update['status']) : 0;
            if ($status_code >= 3) { // 3 = DELIVERY_ACK, 4 = READ
                $status = 'delivered';
            }
        }

        // Formato simplificado (n8n ou custom)
        if (!$phone && isset($body['telefone'])) {
            $phone = $body['telefone'];
        }
        if (!$phone && isset($body['phone'])) {
            $phone = $body['phone'];
        }
        if (!$phone && isset($body['remoteJid'])) {
            $phone = preg_replace('/@.*$/', '', $body['remoteJid']);
        }

        if (!$status && isset($body['status'])) {
            $raw_status = strtolower($body['status']);
            if (in_array($raw_status, array('delivered', 'read', 'delivery_ack', 'played'))) {
                $status = 'delivered';
            }
        }

        if (isset($body['lead_id'])) {
            $lead_id = sanitize_text_field($body['lead_id']);
        }

        // Se não conseguiu extrair telefone, retorna erro
        if (!$phone) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Telefone não encontrado no payload'
            ), 400);
        }

        $phone = $this->normalize_phone($phone);

        // Se o status indica entrega, confirma
        if ($status === 'delivered') {
            $confirmed = $this->confirm_delivery($phone, $lead_id);

            return new WP_REST_Response(array(
                'success' => $confirmed,
                'message' => $confirmed ? 'Entrega confirmada' : 'Nenhuma entrega pendente encontrada para este número',
                'phone' => $phone
            ), 200);
        }

        // Status não é de entrega - apenas registra
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Webhook recebido (status não é de entrega)',
            'phone' => $phone
        ), 200);
    }

    /**
     * Confirma a entrega de uma mensagem
     */
    private function confirm_delivery($phone, $lead_id = null)
    {
        $pending = get_option(self::OPTION_PENDING, array());
        $confirmed = false;

        foreach ($pending as &$delivery) {
            if ($delivery['status'] !== 'pendente') {
                continue;
            }

            // Verifica por lead_id (mais preciso) ou telefone
            $match = false;
            if ($lead_id && $delivery['lead_id'] === $lead_id) {
                $match = true;
            } elseif ($this->phones_match($delivery['vendedor_telefone'], $phone)) {
                $match = true;
            }

            if ($match) {
                $delivery['status'] = 'entregue';
                $delivery['confirmado_em'] = current_time('mysql');
                $confirmed = true;

                error_log("HAPVIDA DELIVERY: Entrega confirmada - Lead {$delivery['lead_id']} para {$delivery['vendedor_nome']} ({$phone})");

                // Se confirmou por lead_id, para aqui
                if ($lead_id) {
                    break;
                }

                // Se confirmou por telefone, confirma todas as pendentes desse vendedor
            }
        }

        if ($confirmed) {
            update_option(self::OPTION_PENDING, $pending);
        }

        return $confirmed;
    }

    // =========================================================================
    // WP-CRON: VERIFICA ENTREGAS EXPIRADAS
    // =========================================================================

    /**
     * Verifica se está em horário comercial (8h às 18h, seg-sex)
     */
    private function is_horario_comercial()
    {
        $current_hour = intval(current_time('H'));
        $current_day = intval(current_time('N')); // 1=seg, 7=dom
        return ($current_hour >= 8 && $current_hour < 18 && $current_day <= 5);
    }

    /**
     * Verifica entregas pendentes que passaram do timeout de 2 horas
     * Desativa automaticamente os vendedores que não receberam
     *
     * IMPORTANTE: Só desativa em horário comercial (8h-18h, seg-sex).
     * Fora do horário, o vendedor pode estar com internet desligada (dormindo).
     */
    public function check_expired_deliveries()
    {
        $pending = get_option(self::OPTION_PENDING, array());

        if (empty($pending)) {
            return;
        }

        $is_business_hours = $this->is_horario_comercial();

        $now = time();
        $vendedores = get_option(self::OPTION_VENDORS, array());
        $deactivation_log = get_option(self::OPTION_DEACTIVATION_LOG, array());
        $has_changes = false;
        $has_vendor_changes = false;

        foreach ($pending as &$delivery) {
            if ($delivery['status'] !== 'pendente') {
                continue;
            }

            $elapsed = $now - $delivery['enviado_timestamp'];

            // Se passou do timeout (2 horas)
            if ($elapsed >= self::DELIVERY_TIMEOUT) {

                // Fora do horário comercial: NÃO desativa, apenas loga
                if (!$is_business_hours) {
                    error_log("HAPVIDA DELIVERY: TIMEOUT ignorado (fora do horário comercial) - Lead {$delivery['lead_id']} para {$delivery['vendedor_nome']}");
                    continue;
                }

                $delivery['status'] = 'expirado';

                error_log("HAPVIDA DELIVERY: TIMEOUT - Lead {$delivery['lead_id']} para {$delivery['vendedor_nome']} - {$elapsed}s sem confirmação");

                // Inativa o vendedor
                $deactivated = $this->deactivate_vendor(
                    $vendedores,
                    $delivery['vendedor_telefone'],
                    $delivery['grupo']
                );

                if ($deactivated) {
                    $has_vendor_changes = true;

                    // Registra no log de inativações
                    $deactivation_log[] = array(
                        'vendedor_nome' => $delivery['vendedor_nome'],
                        'vendedor_telefone' => $delivery['vendedor_telefone'],
                        'grupo' => $delivery['grupo'],
                        'lead_id' => $delivery['lead_id'],
                        'enviado_em' => $delivery['enviado_em'],
                        'inativado_em' => current_time('mysql'),
                        'motivo' => 'Sem confirmação de entrega em ' . round(self::DELIVERY_TIMEOUT / 3600, 1) . 'h'
                    );

                    error_log("HAPVIDA DELIVERY: VENDEDOR INATIVADO - {$delivery['vendedor_nome']} ({$delivery['vendedor_telefone']}) no grupo {$delivery['grupo']}");
                }

                $has_changes = true;
            }
        }

        if ($has_changes) {
            update_option(self::OPTION_PENDING, $pending);
        }

        if ($has_vendor_changes) {
            update_option(self::OPTION_VENDORS, $vendedores);

            // Mantém apenas os últimos 100 registros no log
            if (count($deactivation_log) > 100) {
                $deactivation_log = array_slice($deactivation_log, -100);
            }
            update_option(self::OPTION_DEACTIVATION_LOG, $deactivation_log);
        }

        // Limpa entregas antigas (mais de 7 dias) para não crescer infinitamente
        $this->cleanup_old_deliveries();
    }

    /**
     * Desativa um vendedor pelo telefone e grupo
     *
     * @return bool true se desativou, false se não encontrou ou já estava inativo
     */
    private function deactivate_vendor(&$vendedores, $phone, $grupo)
    {
        if (!isset($vendedores[$grupo]) || !is_array($vendedores[$grupo])) {
            return false;
        }

        foreach ($vendedores[$grupo] as &$vendedor) {
            $vendor_phone = $this->normalize_phone($vendedor['telefone']);
            if ($this->phones_match($vendor_phone, $phone)) {
                if (!isset($vendedor['status']) || $vendedor['status'] === 'ativo') {
                    $vendedor['status'] = 'inativo';
                    return true;
                }
                return false; // Já estava inativo
            }
        }

        return false; // Não encontrou
    }

    /**
     * Limpa entregas com mais de 7 dias
     */
    private function cleanup_old_deliveries()
    {
        $pending = get_option(self::OPTION_PENDING, array());
        $seven_days_ago = time() - (7 * 86400);

        $cleaned = array_filter($pending, function ($delivery) use ($seven_days_ago) {
            return $delivery['enviado_timestamp'] > $seven_days_ago;
        });

        if (count($cleaned) !== count($pending)) {
            update_option(self::OPTION_PENDING, array_values($cleaned));
        }
    }

    // =========================================================================
    // ENDPOINT DE STATUS (para admin)
    // =========================================================================

    /**
     * Retorna status das entregas (GET /formulario-hapvida/v1/delivery-status)
     */
    public function get_delivery_status($request)
    {
        $pending = get_option(self::OPTION_PENDING, array());
        $log = get_option(self::OPTION_DEACTIVATION_LOG, array());

        $stats = array(
            'pendentes' => 0,
            'entregues' => 0,
            'expirados' => 0
        );

        foreach ($pending as $delivery) {
            if (isset($stats[$delivery['status'] . 's'])) {
                // pendente -> pendentes, etc.
            }
            switch ($delivery['status']) {
                case 'pendente':
                    $stats['pendentes']++;
                    break;
                case 'entregue':
                    $stats['entregues']++;
                    break;
                case 'expirado':
                    $stats['expirados']++;
                    break;
            }
        }

        return new WP_REST_Response(array(
            'success' => true,
            'stats' => $stats,
            'recent_deliveries' => array_slice(array_reverse($pending), 0, 20),
            'recent_deactivations' => array_slice(array_reverse($log), 0, 10),
            'timeout_hours' => self::DELIVERY_TIMEOUT / 3600,
            'next_check' => wp_next_scheduled(self::CRON_HOOK)
                ? date('Y-m-d H:i:s', wp_next_scheduled(self::CRON_HOOK))
                : 'Não agendado'
        ), 200);
    }

    // =========================================================================
    // UTILITÁRIOS
    // =========================================================================

    /**
     * Normaliza um telefone removendo caracteres não numéricos
     */
    private function normalize_phone($phone)
    {
        $phone = preg_replace('/\D/', '', $phone);

        // Se começa com 55 e tem 12-13 dígitos, é BR com DDI
        // Se tem 10-11 dígitos, adiciona DDI 55
        if (strlen($phone) >= 10 && strlen($phone) <= 11) {
            $phone = '55' . $phone;
        }

        return $phone;
    }

    /**
     * Verifica se dois telefones correspondem ao mesmo número
     */
    private function phones_match($phone1, $phone2)
    {
        $p1 = $this->normalize_phone($phone1);
        $p2 = $this->normalize_phone($phone2);

        // Comparação direta
        if ($p1 === $p2) {
            return true;
        }

        // Tenta sem o 9 extra (celulares BR)
        // Ex: 5511999999999 vs 551199999999
        $shorter1 = strlen($p1) === 13 ? substr($p1, 0, 4) . substr($p1, 5) : $p1;
        $shorter2 = strlen($p2) === 13 ? substr($p2, 0, 4) . substr($p2, 5) : $p2;

        return $shorter1 === $shorter2 || $p1 === $shorter2 || $shorter1 === $p2;
    }

    /**
     * Retorna entregas pendentes de um vendedor específico
     */
    public function get_pending_for_vendor($phone)
    {
        $pending = get_option(self::OPTION_PENDING, array());
        $phone = $this->normalize_phone($phone);

        return array_filter($pending, function ($delivery) use ($phone) {
            return $delivery['status'] === 'pendente' && $this->phones_match($delivery['vendedor_telefone'], $phone);
        });
    }

    /**
     * Retorna o log de inativações automáticas
     */
    public function get_deactivation_log()
    {
        return get_option(self::OPTION_DEACTIVATION_LOG, array());
    }

    /**
     * Retorna resumo das entregas para exibição no dashboard/shortcode
     */
    public function get_stats_summary()
    {
        $pending = get_option(self::OPTION_PENDING, array());
        $log = get_option(self::OPTION_DEACTIVATION_LOG, array());
        $now = time();

        $stats = array(
            'pendentes' => 0,
            'entregues' => 0,
            'expirados' => 0,
            'pendentes_list' => array(),
            'inativacoes_recentes' => array()
        );

        foreach ($pending as $delivery) {
            switch ($delivery['status']) {
                case 'pendente':
                    $stats['pendentes']++;
                    $elapsed_min = round(($now - $delivery['enviado_timestamp']) / 60);
                    $stats['pendentes_list'][] = array(
                        'vendedor' => $delivery['vendedor_nome'],
                        'grupo' => $delivery['grupo'],
                        'minutos' => $elapsed_min,
                        'lead_id' => $delivery['lead_id']
                    );
                    break;
                case 'entregue':
                    $stats['entregues']++;
                    break;
                case 'expirado':
                    $stats['expirados']++;
                    break;
            }
        }

        // Últimas 5 inativações
        $stats['inativacoes_recentes'] = array_slice(array_reverse($log), 0, 5);

        return $stats;
    }

    /**
     * Limpa o log de inativações
     */
    public function clear_deactivation_log()
    {
        delete_option(self::OPTION_DEACTIVATION_LOG);
    }

    /**
     * Limpa todas as entregas pendentes
     */
    public function clear_pending_deliveries()
    {
        delete_option(self::OPTION_PENDING);
    }
}

// Inicializa o sistema
global $hapvida_delivery_tracking;
$hapvida_delivery_tracking = new Hapvida_Delivery_Tracking();
