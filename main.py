import os
import requests
import json
import time
import subprocess
from datetime import datetime, timedelta
from flask import Flask, send_from_directory
from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup
from telegram.ext import Updater, CommandHandler, CallbackContext
from psutil import virtual_memory, disk_usage

# Caminho do arquivo de configuração do bot
CONFIG_FILE = "/root/iptv_config.env"

# Verificar se o arquivo de configuração existe e carregar o token
if os.path.exists(CONFIG_FILE):
    with open(CONFIG_FILE) as f:
        for line in f:
            if "BOT_TOKEN" in line:
                BOT_TOKEN = line.split("=")[1].strip().replace('"', '')
else:
    print("Configuração não encontrada! Execute o install.sh primeiro.")
    exit(1)

# Configuração do IPTV
IPTV_URL = "http://dns.carnes.ink:80/get.php"
USERNAME = "seu_usuario"
PASSWORD = "sua_senha"

# Criar pasta de armazenamento
DATA_DIR = "/root/iptv-bot/data"
os.makedirs(DATA_DIR, exist_ok=True)

# Função para baixar as listas de IPTV
def baixar_listas():
    categorias = {
        "TV_Ao_Vivo": f"{IPTV_URL}?username={USERNAME}&password={PASSWORD}&type=m3u_plus",
        "Filmes": f"{IPTV_URL}?username={USERNAME}&password={PASSWORD}&action=get_vod_streams",
        "Séries": f"{IPTV_URL}?username={USERNAME}&password={PASSWORD}&action=get_series"
    }

    for nome, url in categorias.items():
        try:
            response = requests.get(url)
            if response.status_code == 200:
                with open(f"{DATA_DIR}/{nome}.m3u", "w") as f:
                    f.write(response.text)
        except Exception as e:
            print(f"Erro ao baixar {nome}: {e}")

# Função para gerar a URL do IPTV com a expiração
def gerar_url_iptv(user_id):
    expiration_date = datetime.now() + timedelta(days=7)  # Definindo 7 dias de expiração
    expiration_timestamp = expiration_date.timestamp()

    url = f"http://{os.popen('curl -s ifconfig.me').read().strip()}/TV_Ao_Vivo.m3u?exp={expiration_timestamp}&user={user_id}"
    
    # Salvar as informações do usuário com a data de expiração
    with open(f"{DATA_DIR}/usuarios.json", "a") as users_file:
        user_data = {
            "user_id": user_id,
            "expiration_date": expiration_date.strftime('%Y-%m-%d %H:%M:%S'),
            "url": url
        }
        json.dump(user_data, users_file)
        users_file.write("\n")
    
    # Gerar o formato personalizado com emojis
    mensagem_iptv = (
        f"📡 **Sua Lista IPTV está pronta!**\n\n"
        f"🔗 **URL:** {url}\n\n"
        f"👤 **Usuário:** {USERNAME}\n"
        f"🔑 **Senha:** {PASSWORD}\n\n"
        f"📅 **Data de Expiração:** {expiration_date.strftime('%d/%m/%Y %H:%M:%S')}\n\n"
        f"⏳ Sua lista expira em 7 dias após a geração."
    )
    
    return mensagem_iptv

# Função de inicialização do bot no Telegram
def start(update: Update, context: CallbackContext):
    keyboard = [
        [InlineKeyboardButton("Gerar Nova Lista IPTV", callback_data="generate")]
    ]
    reply_markup = InlineKeyboardMarkup(keyboard)
    update.message.reply_text("Bem-vindo! Clique no botão abaixo para gerar sua lista IPTV.", reply_markup=reply_markup)

# Função para lidar com os botões do Telegram
def button(update: Update, context: CallbackContext):
    query = update.callback_query
    user_id = query.from_user.id  # Obtendo o ID do usuário do Telegram

    if query.data == "generate":
        baixar_listas()  # Baixar as listas IPTV
        mensagem = gerar_url_iptv(user_id)  # Gerar a mensagem personalizada
        query.message.reply_text(mensagem)  # Enviar a mensagem personalizada com a URL, usuário, senha e data de expiração

        # Atualizar o botão para permitir gerar uma nova lista
        keyboard = [[InlineKeyboardButton("Gerar Nova Lista IPTV", callback_data="generate")]]
        reply_markup = InlineKeyboardMarkup(keyboard)
        query.message.reply_text("Clique abaixo para gerar uma nova lista IPTV.", reply_markup=reply_markup)

# Comando para iniciar o bot do Telegram
def iniciar_bot():
    updater = Updater(BOT_TOKEN, use_context=True)
    dp = updater.dispatcher
    dp.add_handler(CommandHandler("start", start))
    dp.add_handler(CommandHandler("gerar", lambda update, context: update.message.reply_text(gerar_url_iptv(update.message.from_user.id))))
    dp.add_handler(CommandHandler("atualizar", lambda update, context: baixar_listas()))
    dp.add_handler(CallbackQueryHandler(button))  # Adicionando o handler do botão para gerar nova lista
    updater.start_polling()
    updater.idle()

# Função para iniciar o servidor Flask para servir as listas IPTV
def iniciar_flask():
    app = Flask(__name__)

    @app.route('/data/<filename>')
    def download_file(filename):
        return send_from_directory(DATA_DIR, filename)

    app.run(host='0.0.0.0', port=80)

# Função de teste de velocidade (SpeedTest)
def testar_velocidade():
    try:
        result = subprocess.run(['speedtest-cli', '--json'], stdout=subprocess.PIPE)
        speed_data = json.loads(result.stdout.decode())
        download_speed = speed_data['download'] / 1_000_000  # Em Mbps
        upload_speed = speed_data['upload'] / 1_000_000  # Em Mbps
        return f"Download: {download_speed:.2f} Mbps\nUpload: {upload_speed:.2f} Mbps"
    except Exception as e:
        print(f"Erro ao testar a velocidade: {e}")
        return "Erro ao testar a velocidade."

# Função de exibição da RAM e armazenamento
def exibir_status():
    ram = virtual_memory()
    armazenamento = disk_usage("/")
    
    tempo_ligada = time.time() - psutil.boot_time()
    minutos_ligada = tempo_ligada // 60

    status = (
        f"🖥️ Tempo de Atividade: {minutos_ligada} minutos\n"
        f"💾 Memória RAM: {ram.percent}% de {ram.total / (1024 ** 3):.2f} GB\n"
        f"📦 Armazenamento: {armazenamento.percent}% de {armazenamento.total / (1024 ** 3):.2f} GB"
    )
    return status

# Função principal
if __name__ == "__main__":
    # Baixar as listas IPTV ao iniciar
    baixar_listas()
    
    # Iniciar o servidor Flask em um processo separado
    subprocess.Popen(["python3", "-m", "flask", "run", "--host=0.0.0.0", "--port=80"], cwd=DATA_DIR)
    
    # Iniciar o bot do Telegram
    iniciar_bot()

    # Criar a opção de autoexecução para a VPS no boot
    with open("/etc/rc.local", "a") as rc_local:
        rc_local.write("\npython3 /root/seu_script.py &\n")
    
    # Exibir status de RAM e armazenamento
    print(exibir_status())
