#!/bin/bash

# Função para exibir o painel
exibir_painel() {
    echo "============================"
    echo " VenusProIPTV - Instalação "
    echo "============================"
    echo "Sua solução IPTV personalizada."
    echo "Iniciando instalação..."
    echo "============================"
}

# Verificar se o usuário tem permissões de root
verificar_root() {
    if [ "$(id -u)" -ne 0 ]; then
        echo "Script não pode ser instalado. Você precisa de permissões de root."
        exit 1
    fi
    echo "Root verificado com sucesso."
}

# Atualizar pacotes e instalar dependências
instalar_dependencias() {
    echo "Atualizando pacotes e instalando dependências..."
    apt update && apt upgrade -y
    apt install -y python3 python3-pip nginx git curl
}

# Instalar dependências Python do repositório
instalar_dependencias_python() {
    echo "Instalando dependências Python..."
    pip3 install requests flask python-telegram-bot psutil
    pip3 install speedtest-cli  # Para o comando de teste de velocidade, se necessário
}

# Baixar o script Python (main.py) do repositório
baixar_script_python() {
    echo "Baixando o script Python (main.py)..."
    cd /root
    curl -O https://raw.githubusercontent.com/Venusofcxp/IPTV-PRO/refs/heads/main/main.py
}

# Configuração do Nginx
configurar_nginx() {
    echo "Configurando o Nginx..."
    cat > /etc/nginx/sites-available/iptv <<EOF
server {
    listen 80;
    server_name _;

    location / {
        root /root/iptv-bot/data;
        autoindex on;
    }
}
EOF

    ln -s /etc/nginx/sites-available/iptv /etc/nginx/sites-enabled/
    systemctl restart nginx
}

# Iniciar o bot IPTV
iniciar_bot() {
    echo "Iniciando o bot IPTV..."
    nohup python3 /root/main.py &
}

# Função principal de instalação
instalar() {
    exibir_painel
    verificar_root
    instalar_dependencias
    baixar_script_python
    instalar_dependencias_python
    configurar_nginx
    iniciar_bot
    echo "Instalação concluída! O bot e o servidor IPTV estão rodando."
}

# Chamar a função de instalação
instalar
