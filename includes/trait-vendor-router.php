<?php
if (!defined('ABSPATH')) exit;

trait VendorRouterTrait {

    private function get_next_vendedor($cidade = '', $pagina_origem = '')
    {
        // *** NOVA LÓGICA: Verifica se a página tem consultor específico ***
        if (!empty($pagina_origem)) {
            $vendedor_especifico = $this->get_vendedor_por_url($pagina_origem);
            if ($vendedor_especifico) {
                return $vendedor_especifico;
            }
        }

        // *** NOVA LÓGICA: Verifica se a cidade tem vendedor específico ***
        if (!empty($cidade)) {
            $vendedor_especifico = $this->get_vendedor_por_cidade($cidade);
            if ($vendedor_especifico) {
                return $vendedor_especifico;
            }
        }

        // Verifica se as configurações foram carregadas corretamente
        if (method_exists($this, 'load_timeout_settings')) {
            $this->load_timeout_settings();
        }

        // Recupera as infos do último vendedor com estrutura correta
        $ultimo_vendedor_info = get_option($this->ultimo_vendedor_option_name, array(
            'group' => '',
            'indices' => array('drv' => -1, 'seu_souza' => -1),
        ));

        // Usa o nome correto da opção de vendedores
        $vendedores = get_option($this->vendedores_option, array('drv' => array(), 'seu_souza' => array()));

        // Verifica se a estrutura de vendedores está correta
        if (!is_array($vendedores) || (!isset($vendedores['drv']) && !isset($vendedores['seu_souza']))) {
            $this->log("ERRO: Estrutura de vendedores invalida");
            return null;
        }

        // Filtra apenas vendedores ativos com verificação robusta
        $vendedores_ativos = array();
        foreach ($vendedores as $grupo => $vendedores_grupo) {
            if (!is_array($vendedores_grupo)) {
                $vendedores_ativos[$grupo] = array();
                continue;
            }

            $vendedores_ativos[$grupo] = array_filter($vendedores_grupo, function ($vendedor) {
                // VERIFICAÇÃO: Considera apenas vendedores ATIVOS
                return is_array($vendedor) &&
                    isset($vendedor['nome']) &&
                    !empty($vendedor['nome']) &&
                    (!isset($vendedor['status']) || $vendedor['status'] === 'ativo');
            });

            // IMPORTANTE: Reindexar arrays após filtrar
            $vendedores_ativos[$grupo] = array_values($vendedores_ativos[$grupo]);
        }

        $count_drv = count($vendedores_ativos['drv']);
        $count_seu_souza = count($vendedores_ativos['seu_souza']);
        $this->log("Total de vendedores ATIVOS: DRV={$count_drv}, Seu Souza={$count_seu_souza}");

        // Se não houver nenhum ativo, retorna null
        if ($count_drv === 0 && $count_seu_souza === 0) {
            $this->log("ERRO: Nenhum vendedor ATIVO cadastrado no sistema");
            return null;
        }

        $ultimo_grupo = isset($ultimo_vendedor_info['group']) ? $ultimo_vendedor_info['group'] : '';
        $this->log("Ultimo grupo utilizado: '{$ultimo_grupo}'");
        $this->log("Indices atuais - DRV: {$ultimo_vendedor_info['indices']['drv']}, Seu Souza: {$ultimo_vendedor_info['indices']['seu_souza']}");

        // Lógica de alternância mais robusta
        $proximo_grupo = ($ultimo_grupo === 'drv') ? 'seu_souza' : 'drv';

        // Verifica se o próximo grupo tem vendedores ativos
        if (count($vendedores_ativos[$proximo_grupo]) === 0) {
            // Se não tem, usa o outro grupo
            $proximo_grupo = ($proximo_grupo === 'drv') ? 'seu_souza' : 'drv';
            $this->log("Grupo alterado para {$proximo_grupo} por falta de vendedores no grupo anterior");
        }

        // Se ainda assim não tem vendedores, retorna null
        if (count($vendedores_ativos[$proximo_grupo]) === 0) {
            $this->log("ERRO: Nenhum vendedor ativo disponivel em nenhum grupo");
            return null;
        }

        // Obtém o índice do último vendedor usado do grupo
        $ultimo_indice = isset($ultimo_vendedor_info['indices'][$proximo_grupo]) ?
            $ultimo_vendedor_info['indices'][$proximo_grupo] : -1;

        $this->log("Ultimo indice do grupo {$proximo_grupo}: {$ultimo_indice}");

        // Calcula o próximo índice
        $proximo_indice = ($ultimo_indice + 1) % count($vendedores_ativos[$proximo_grupo]);

        $this->log("Proximo indice calculado: {$proximo_indice}");

        // Obtém o vendedor
        $vendedor = $vendedores_ativos[$proximo_grupo][$proximo_indice];

        // IMPORTANTE: Adiciona o grupo ao vendedor e garante que o ID está presente
        $vendedor['grupo'] = $proximo_grupo;

        // Garante que o vendedor_id existe no retorno
        if (!isset($vendedor['vendedor_id'])) {
            $vendedor['vendedor_id'] = ''; // Define como vazio se não existir
            $this->log("Vendedor sem ID definido: {$vendedor['nome']}");
        } else {
            $this->log("Vendedor com ID: {$vendedor['vendedor_id']}");
        }

        // CORREÇÃO CRÃTICA: ATUALIZA E SALVA OS NOVOS ÃNDICES
        $ultimo_vendedor_info['group'] = $proximo_grupo;
        $ultimo_vendedor_info['indices'][$proximo_grupo] = $proximo_indice;

        // SALVA NO BANCO DE DADOS
        update_option($this->ultimo_vendedor_option_name, $ultimo_vendedor_info);

        $this->log("Indices atualizados e salvos - Grupo: {$proximo_grupo}, Indice: {$proximo_indice}");
        $this->log("Vendedor selecionado: {$vendedor['nome']} (Grupo: {$proximo_grupo}, ID: {$vendedor['vendedor_id']})");

        return $vendedor;
    }

    private function get_vendedor_por_cidade($cidade)
    {
        // Remove acentos e converte para minúsculas para comparação
        $cidade_normalizada = $this->normalizar_cidade($cidade);

        // Busca as configurações de vendedores por cidade
        $city_vendors = get_option($this->city_vendors_option, array());

        if (empty($city_vendors) || !is_array($city_vendors)) {
            return null;
        }

        // Procura por uma configuração para esta cidade
        foreach ($city_vendors as $config) {
            if (!isset($config['cidade']) || !isset($config['vendedor_grupo']) || !isset($config['vendedor_index'])) {
                continue;
            }

            $cidade_config_normalizada = $this->normalizar_cidade($config['cidade']);

            if ($cidade_config_normalizada === $cidade_normalizada) {
                // Encontrou! Busca o vendedor específico
                $vendedores = get_option($this->vendedores_option, array('drv' => array(), 'seu_souza' => array()));
                $grupo = $config['vendedor_grupo'];
                $index = $config['vendedor_index'];

                if (isset($vendedores[$grupo][$index])) {
                    $vendedor = $vendedores[$grupo][$index];

                    // Verifica se o vendedor está ativo
                    if (isset($vendedor['status']) && $vendedor['status'] !== 'ativo') {
                        $this->log("Vendedor especifico para {$cidade} esta inativo");
                        return null;
                    }

                    // Adiciona informações do grupo
                    $vendedor['grupo'] = $grupo;

                    // Garante que o vendedor_id existe
                    if (!isset($vendedor['vendedor_id'])) {
                        $vendedor['vendedor_id'] = '';
                    }

                    $this->log("Vendedor especifico para {$cidade}: {$vendedor['nome']} (Grupo: {$grupo})");
                    return $vendedor;
                }
            }
        }

        return null;
    }

    private function normalizar_cidade($cidade)
    {
        // Remove acentos
        $cidade = remove_accents($cidade);
        // Converte para minúsculas
        $cidade = strtolower($cidade);
        // Remove espaços extras
        $cidade = trim($cidade);
        // Remove caracteres especiais
        $cidade = preg_replace('/[^a-z0-9\s]/', '', $cidade);

        return $cidade;
    }

    private function get_vendedor_por_url($pagina_origem)
    {
        // Normaliza a URL para comparação
        $url_normalizada = $this->normalizar_url($pagina_origem);

        // Busca as configurações de URLs de consultores
        $url_consultores = get_option($this->url_consultores_option, array());

        if (empty($url_consultores) || !is_array($url_consultores)) {
            return null;
        }

        // Procura por uma configuração para esta URL
        foreach ($url_consultores as $config) {
            if (!isset($config['url']) || !isset($config['vendedor_numero'])) {
                continue;
            }

            $url_config_normalizada = $this->normalizar_url($config['url']);

            // Verifica se a URL de origem contém a URL configurada
            if (strpos($url_normalizada, $url_config_normalizada) !== false) {
                // Encontrou! Busca o vendedor pelo número
                $vendedor = $this->get_vendedor_por_numero($config['vendedor_numero']);

                if ($vendedor) {
                    return $vendedor;
                }
            }
        }

        return null;
    }

    private function normalizar_url($url)
    {
        // Remove protocolo
        $url = preg_replace('/^https?:\/\//', '', $url);
        // Remove www
        $url = preg_replace('/^www\./', '', $url);
        // Converte para minúsculas
        $url = strtolower($url);
        // Remove barra final
        $url = rtrim($url, '/');

        return $url;
    }

    private function get_vendedor_por_numero($numero)
    {
        // Remove caracteres especiais do número para comparação
        $numero_limpo = preg_replace('/[^0-9]/', '', $numero);

        // Busca todos os vendedores
        $vendedores = get_option($this->vendedores_option, array('drv' => array(), 'seu_souza' => array()));

        // Procura em ambos os grupos
        foreach ($vendedores as $grupo => $vendedores_grupo) {
            if (!is_array($vendedores_grupo)) {
                continue;
            }

            foreach ($vendedores_grupo as $vendedor) {
                if (!is_array($vendedor)) {
                    continue;
                }

                // Tenta pegar o número de diferentes campos possíveis
                $vendedor_numero = '';
                if (isset($vendedor['numero']) && !empty($vendedor['numero'])) {
                    $vendedor_numero = $vendedor['numero'];
                } elseif (isset($vendedor['telefone']) && !empty($vendedor['telefone'])) {
                    $vendedor_numero = $vendedor['telefone'];
                } else {
                    continue;
                }

                // Limpa o número do vendedor
                $vendedor_numero_limpo = preg_replace('/[^0-9]/', '', $vendedor_numero);

                // Compara os números
                if ($vendedor_numero_limpo === $numero_limpo) {
                    // Verifica se o vendedor está ativo
                    if (isset($vendedor['status']) && $vendedor['status'] !== 'ativo') {
                        return null;
                    }

                    // Adiciona informações do grupo
                    $vendedor['grupo'] = $grupo;

                    // Garante que o vendedor_id existe
                    if (!isset($vendedor['vendedor_id'])) {
                        $vendedor['vendedor_id'] = '';
                    }

                    return $vendedor;
                }
            }
        }

        return null;
    }

    private function fix_ultimo_vendedor_option()
    {
        $ultimo_vendedor = get_option($this->ultimo_vendedor_option_name);

        if (empty($ultimo_vendedor) || !is_array($ultimo_vendedor) || !isset($ultimo_vendedor['indices'])) {
            update_option($this->ultimo_vendedor_option_name, array(
                'group' => '',
                'indices' => array('drv' => -1, 'seu_souza' => -1)
            ));
            $this->log("Opção de último vendedor corrigida/inicializada");
        }
    }
}
