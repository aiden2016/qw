import os
import socket
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request
import webbrowser
from pathlib import Path
from tkinter import Button, Label, StringVar, Tk, messagebox


APP_TITLE = "AIDEN 网站"
SITE_FILES = (
    "app.php",
    "index.html",
    "aiden.txt",
    "答辩.txt",
    ".users.json",
    ".resources.json",
    ".categories.json",
    ".announcements.json",
    ".password-resets.php",
    ".htaccess",
    "nginx.htaccess",
    ".maintenance.lock",
)


def bundle_directory():
    if getattr(sys, "frozen", False):
        return Path(sys.executable).resolve().parent
    return Path(__file__).resolve().parent


def writable_directory():
    local_app_data = os.environ.get("LOCALAPPDATA")
    if local_app_data:
        return Path(local_app_data) / "AidenWebsite"
    return Path.home() / "AppData" / "Local" / "AidenWebsite"


class WebsiteLauncher:
    def __init__(self):
        self.root = Tk()
        self.root.title(APP_TITLE)
        self.root.geometry("420x190")
        self.root.resizable(False, False)
        self.root.protocol("WM_DELETE_WINDOW", self.close)

        self.status = StringVar(value="正在启动本地网站……")
        Label(self.root, text="AIDEN / 官方资源中心", font=("Segoe UI", 15, "bold")).pack(pady=(24, 8))
        Label(self.root, textvariable=self.status, wraplength=370).pack(pady=4)
        self.open_button = Button(self.root, text="打开网站", state="disabled", command=self.open_site)
        self.open_button.pack(pady=(14, 4))

        self.closing = False
        self.process = None
        self.log_file = None
        self.site_url = None
        self.process_lock = threading.Lock()
        threading.Thread(target=self.start_server, daemon=True).start()

    def start_server(self):
        try:
            bundle = bundle_directory()
            php_directory = bundle / "php"
            php_executable = php_directory / "php.exe"
            seed_directory = bundle / "site"
            data_directory = writable_directory()
            site_directory = data_directory / "site"
            session_directory = data_directory / "sessions"
            log_path = data_directory / "server.log"

            if not php_executable.is_file():
                raise RuntimeError("未找到随程序附带的 PHP 运行环境。请解压完整程序目录后再启动。")
            if not (seed_directory / "app.php").is_file():
                raise RuntimeError("未找到网站文件。请解压完整程序目录后再启动。")

            site_directory.mkdir(parents=True, exist_ok=True)
            session_directory.mkdir(parents=True, exist_ok=True)
            for name in SITE_FILES:
                source = seed_directory / name
                destination = site_directory / name
                if source.is_file() and not destination.exists():
                    destination.parent.mkdir(parents=True, exist_ok=True)
                    destination.write_bytes(source.read_bytes())

            with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as listener:
                listener.bind(("127.0.0.1", 0))
                port = listener.getsockname()[1]

            command = [
                str(php_executable),
                "-n",
                "-d",
                "extension_dir=" + str(php_directory / "ext"),
                "-d",
                "extension=php_mbstring.dll",
                "-d",
                "date.timezone=Asia/Shanghai",
                "-d",
                "session.save_path=" + str(session_directory),
                "-S",
                "127.0.0.1:" + str(port),
                "-t",
                str(site_directory),
            ]
            environment = os.environ.copy()
            pyinstaller_directory = getattr(sys, "_MEIPASS", None)
            if pyinstaller_directory:
                excluded_directory = os.path.normcase(os.path.abspath(pyinstaller_directory))
                environment["PATH"] = os.pathsep.join(
                    entry
                    for entry in environment.get("PATH", "").split(os.pathsep)
                    if os.path.normcase(os.path.abspath(entry.strip('"'))) != excluded_directory
                )
            self.log_file = open(log_path, "ab")
            with self.process_lock:
                if self.closing:
                    return
                self.process = subprocess.Popen(
                    command,
                    cwd=str(site_directory),
                    stdin=subprocess.DEVNULL,
                    stdout=self.log_file,
                    stderr=subprocess.STDOUT,
                    creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
                    env=environment,
                )

            self.site_url = "http://127.0.0.1:{}/index.html".format(port)
            deadline = time.monotonic() + 15
            while time.monotonic() < deadline:
                with self.process_lock:
                    process = self.process
                    should_close = self.closing
                if should_close:
                    self.stop_server()
                    return
                if process is None or process.poll() is not None:
                    raise RuntimeError("PHP 服务启动失败。请查看日志：{}".format(log_path))
                try:
                    with urllib.request.urlopen(self.site_url, timeout=1):
                        self.root.after(0, self.server_ready)
                        return
                except (urllib.error.URLError, TimeoutError, OSError):
                    time.sleep(0.2)
            raise RuntimeError("等待本地网站启动超时。请查看日志：{}".format(log_path))
        except Exception as error:
            self.stop_server()
            self.root.after(0, self.show_error, str(error))

    def server_ready(self):
        if self.closing:
            return
        self.status.set("网站已在本机运行：" + self.site_url)
        self.open_button.config(state="normal")
        self.open_site()

    def open_site(self):
        if self.site_url:
            webbrowser.open(self.site_url, new=2)

    def show_error(self, message):
        if self.closing:
            return
        self.status.set("网站启动失败。")
        messagebox.showerror(APP_TITLE, message, parent=self.root)

    def stop_server(self):
        with self.process_lock:
            process = self.process
            self.process = None
        if process is not None and process.poll() is None:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()
        if self.log_file is not None:
            self.log_file.close()
            self.log_file = None

    def close(self):
        self.closing = True
        self.stop_server()
        self.root.destroy()

    def run(self):
        self.root.mainloop()


if __name__ == "__main__":
    WebsiteLauncher().run()
