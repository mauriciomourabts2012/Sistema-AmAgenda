<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| INFOBIP SMS API v3 - CONFIGURAÇÃO PRIVADA (MODELO)
|--------------------------------------------------------------------------
|
| Copie este arquivo para backend/_config/infobip.php e preencha os valores
| manualmente no servidor. O arquivo privado:
| - fica fora de /public;
| - é ignorado pelo Git (.gitignore);
| - nunca deve ser versionado, colado em chat, log ou documentação.
|
| O envio permanece DESABILITADO enquanto 'habilitado' não for exatamente true.
|
*/

return [

    // Chave geral do envio. Qualquer valor diferente de true mantém o SMS desligado.
    'habilitado' => false,

    // 'teste' ou 'producao'. Fica gravado em cada mensagem_envio.
    'ambiente' => 'teste',

    // Base URL individual da conta (painel Infobip). Somente HTTPS e host *.api.infobip.com.
    'base_url' => 'https://SEU-SUBDOMINIO.api.infobip.com',

    // API key com escopo de envio de SMS (sms:message:send). Preencher somente no servidor.
    'api_key' => '',

    // Remetente aprovado: 3 a 11 letras/números, ou número de 3 a 16 dígitos.
    'sender' => '',

    // Timeouts em segundos.
    'timeout_conexao' => 5,
    'timeout_total' => 15,

    // Chave HMAC para hashes do OTP, telefone, IP, sessão e callbackData.
    // Mínimo 32 caracteres aleatórios. Deve ser DIFERENTE de webhook_secret.
    'hmac_key' => '',

    // Segredo do callback de entrega (token por mensagem na URL). Mínimo 32 caracteres
    // aleatórios, independente de hmac_key.
    'webhook_secret' => '',

    // URL pública HTTPS do webhook, já com o parâmetro da rota. Exemplo:
    // https://SEU-DOMINIO/public/api/api_central.php?path=webhooks/infobip
    'webhook_url' => 'https://SEU-DOMINIO/public/api/api_central.php?path=webhooks/infobip',
];
