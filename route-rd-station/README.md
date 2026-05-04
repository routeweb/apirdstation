# Route RD Station Integration

Plugin WordPress para integrar formularios, cadastro de usuario, Contact Form 7, Elementor Forms e WooCommerce com a RD Station Marketing API.

## Requisitos

- WordPress atual
- PHP 7.4 ou superior
- Extensao OpenSSL recomendada para criptografar credenciais
- Credenciais OAuth2 da RD Station App Store ou API Key RD Station

## Instalacao

1. Copie a pasta `route-rd-station` para `wp-content/plugins/`.
2. Ative o plugin em **Plugins > Plugins instalados**.
3. Acesse **RD Station** no menu administrativo.
4. Em **Conexao**, escolha OAuth2 ou API Key.
5. Para OAuth2, preencha Client ID e Client Secret, cadastre a Callback URL exibida no app da RD Station e clique em **Conectar com RD Station**.
6. Configure o `conversion_identifier`, tags padrao, LGPD, mapeamentos e integracoes opcionais.
7. Use o shortcode `[route_rd_form]` em uma pagina.

## Shortcode

```text
[route_rd_form]
```

Parametros aceitos:

```text
[route_rd_form conversion_identifier="Contato Site" tags="site,contato" button_text="Enviar" redirect="/obrigado"]
```

## Endpoints RD Station usados

- OAuth code: `POST https://api.rd.services/auth/token?token_by=code`
- OAuth refresh: `POST https://api.rd.services/auth/token`
- Conversao OAuth2: `POST https://api.rd.services/platform/events?event_type=conversion`
- Conversao API Key: `POST https://api.rd.services/platform/conversions?api_key=...`
- Atualizar contato: `PATCH https://api.rd.services/platform/contacts/email:{email}`
- Adicionar tags: `POST https://api.rd.services/platform/contacts/email:{email}/tag`

## Logs

A ativacao cria a tabela `wp_route_rd_logs` ou equivalente ao prefixo do site. Os logs podem ser consultados, reenviados e limpos pelo painel **RD Station > Logs**.

## Desinstalacao

Por seguranca, o plugin so remove opcoes e tabela de logs quando a opcao **Remover dados ao desinstalar** estiver ativa em **Configuracoes de Conversao**.
