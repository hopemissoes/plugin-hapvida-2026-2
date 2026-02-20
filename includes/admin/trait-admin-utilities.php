<?php
if (!defined('ABSPATH')) exit;

trait AdminUtilitiesTrait {

    /**
     * *** FUNÇÃO CORRIGIDA: format_time_remaining - Formatação consistente de tempo ***
     * ARQUIVO: admin-page.php
     * LOCALIZAÇÃO: Dentro da classe Formulario_Hapvida_Admin, substitua a função format_time_remaining() existente
     * OU ADICIONE se não existir
     */
    private function format_time_remaining($tempo_restante_segundos)
    {
        // *** CORREÇÃO: Se já expirou ***
        if ($tempo_restante_segundos <= 0) {
            $tempo_passado = abs($tempo_restante_segundos);
            if ($tempo_passado < 60) {
                return '🚨 Expirado há ' . $tempo_passado . 's';
            } elseif ($tempo_passado < 3600) {
                $minutos = floor($tempo_passado / 60);
                $segundos_restantes = $tempo_passado % 60;
                return '🚨 Expirado há ' . $minutos . 'm ' . $segundos_restantes . 's';
            } else {
                $horas = floor($tempo_passado / 3600);
                $minutos = floor(($tempo_passado % 3600) / 60);
                return '🚨 Expirado há ' . $horas . 'h ' . $minutos . 'm';
            }
        }

        // *** CORREÇÃO: Se ainda não expirou ***
        if ($tempo_restante_segundos < 60) {
            return $tempo_restante_segundos . 's';
        } elseif ($tempo_restante_segundos < 3600) {
            $minutes = floor($tempo_restante_segundos / 60);
            $remaining_seconds = $tempo_restante_segundos % 60;
            return $minutes . 'm ' . $remaining_seconds . 's';
        } else {
            $hours = floor($tempo_restante_segundos / 3600);
            $minutes = floor(($tempo_restante_segundos % 3600) / 60);
            return $hours . 'h ' . $minutes . 'm';
        }
    }

    /**
     * *** FUNÇÃO AUXILIAR: get_urgency_status - Para calcular status de urgência ***
     * ADICIONE esta função também na classe Formulario_Hapvida_Admin
     */
    private function get_urgency_status($tempo_restante, $timeout_total_minutos = 10)
    {
        $timeout_total_segundos = $timeout_total_minutos * 60;

        if ($tempo_restante <= 0) {
            return 'expired';
        } elseif ($tempo_restante <= ($timeout_total_segundos * 0.3)) { // 30% restante = urgente
            return 'urgent';
        } elseif ($tempo_restante <= ($timeout_total_segundos * 0.6)) { // 60% restante = aviso
            return 'warning';
        } else {
            return 'normal';
        }
    }

    public function clear_excessive_logs()
    {
        $log_file = WP_CONTENT_DIR . '/formulario_hapvida.log';

        if (file_exists($log_file)) {
            $lines = file($log_file);
            $total_lines = count($lines);

            if ($total_lines > 10000) {
                // Mantém apenas as últimas 5000 linhas
                $keep_lines = array_slice($lines, -5000);
                file_put_contents($log_file, implode('', $keep_lines));

                // Log da limpeza usando timezone correto
                $timezone = new DateTimeZone('America/Fortaleza');
                $timestamp = new DateTime('now', $timezone);
                $log_entry = "[" . $timestamp->format('Y-m-d H:i:s') . "] Log limpo: mantidas 5000 de {$total_lines} linhas" . PHP_EOL;
                error_log($log_entry, 3, $log_file);
            }
        }
    }
}
