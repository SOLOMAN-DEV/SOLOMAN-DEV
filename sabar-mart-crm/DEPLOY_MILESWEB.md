# Deploying the Sabar Mart CRM on MilesWeb

There are two routes:

- **A. Shared or reseller hosting with cPanel.** The app runs under cPanel's **Setup Python App** tool, which uses Phusion Passenger, and stores its data in a cPanel **MySQL/MariaDB** database.
- **B. VPS or cloud hosting.** The app runs under uvicorn or gunicorn behind nginx.

> **Before you start:** *Setup Python App* is not enabled on every shared server. If you can't find it in cPanel under **Software**, ask MilesWeb support to enable it or to move your account to a server that has it. Pick **Python 3.10 or newer**.

---

## A. cPanel shared hosting (Passenger + MySQL)

### 1. Create the database
1. In cPanel, open **Databases → MySQL® Database Wizard**.
2. Create a database such as `crm` (cPanel adds your username as a prefix, giving `cpuser_crm`).
3. Create a user such as `cpuser_crmapp` with a strong password.
4. Grant that user **ALL PRIVILEGES** on the database.

Your connection URL will look like this:
```
mysql+pymysql://cpuser_crmapp:PASSWORD@localhost/cpuser_crm?charset=utf8mb4
```
If the password contains `@ : / ? # %` or similar characters, URL-encode them. For example, `p@ss` becomes `p%40ss`. To get the encoded form, run `python3 -c "import urllib.parse;print(urllib.parse.quote('p@ss', safe=''))"`.

### 2. Upload the code
Put the `sabar-mart-crm/` folder somewhere under your home directory, **outside `public_html`**, for example `/home/cpuser/sabar-mart-crm`. You can use **Git™ Version Control** (clone this repository), **File Manager** (upload a zip and extract it), or SFTP.

### 3. Create the Python app
Go to **Software → Setup Python App → Create Application** and fill in:

| Field | Value |
|---|---|
| Python version | 3.11 (or any version ≥ 3.10) |
| Application root | `sabar-mart-crm` |
| Application URL | for example `crm.sabarmart.com`, or `sabarmart.com/crm` |
| Application startup file | `passenger_wsgi.py` |
| Application Entry point | `application` |

Then add these **environment variables** on the same screen:

| Name | Value |
|---|---|
| `DATABASE_URL` | the URL from step 1 |
| `SABAR_CRM_API_KEYS` | `LONG_RANDOM_KEY_1:admin,LONG_RANDOM_KEY_2:system,…`, one key per role you need |
| `SABAR_CRM_DOCS` | `0` to hide `/docs` in production (optional) |

To generate each key, run `python3 -c "import secrets;print(secrets.token_urlsafe(32))"`.

Click **Create**, then **Save**.

### 4. Install dependencies
On the app's page, enter `requirements.txt` under *Configuration files* and click **Run Pip Install**.

You can do the same over SSH or in cPanel's **Terminal**. The app page shows a `source …/activate` command; run it, then:
```bash
cd ~/sabar-mart-crm
pip install -r requirements.txt
```

### 5. Create the tables
Still inside the activated virtualenv:
```bash
export DATABASE_URL='mysql+pymysql://cpuser_crmapp:PASSWORD@localhost/cpuser_crm?charset=utf8mb4'
python -m sabar_mart_crm.db init      # creates the crm_* tables, prints row counts
python -m sabar_mart_crm.db seed      # OPTIONAL: demo data, only works on an empty database
```
The app also creates any missing tables when it starts. Set `SABAR_CRM_AUTO_MIGRATE=0` to turn that off.

### 6. Restart and verify
Click **Restart** on the app page, then run:
```bash
curl https://crm.sabarmart.com/health                                    # {"status":"ok"}
curl -H "Authorization: Bearer LONG_RANDOM_KEY_1" https://crm.sabarmart.com/me
```
Turn on HTTPS for the domain with **SSL/TLS Status → Run AutoSSL**. API keys must never travel over plain HTTP.

### Updating later
Upload or pull the new code, run **Run Pip Install** again if `requirements.txt` changed, then click **Restart**. Passenger also restarts the app when you run `touch ~/sabar-mart-crm/tmp/restart.txt`.

### Troubleshooting
- **Errors:** the app's `stderr.log` or Passenger log is in the application root. cPanel → **Errors** also helps.
- **`Access denied for user`:** check the user is added to the database with all privileges, and that the password is URL-encoded in `DATABASE_URL`.
- **`503 timed out waiting for the CRM database lock`:** a request held the lock for more than 30 seconds. Look in the logs for a slow or stuck request.
- **`ModuleNotFoundError`:** run **Run Pip Install** again, and check the app is using the Python version you installed into.

---

## B. MilesWeb VPS (uvicorn + nginx)

```bash
sudo apt install python3-venv mariadb-server nginx
sudo mysql -e "CREATE DATABASE sabar_crm CHARACTER SET utf8mb4;
  CREATE USER 'crm'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD';
  GRANT ALL ON sabar_crm.* TO 'crm'@'localhost';"

cd /opt && git clone <this repo> && cd SOLOMAN-DEV/sabar-mart-crm
python3 -m venv .venv && . .venv/bin/activate
pip install -r requirements.txt "uvicorn[standard]" gunicorn
```

Save this as `/etc/systemd/system/sabar-crm.service`:
```ini
[Unit]
Description=Sabar Mart CRM API
After=network.target mariadb.service

[Service]
WorkingDirectory=/opt/SOLOMAN-DEV/sabar-mart-crm
Environment=DATABASE_URL=mysql+pymysql://crm:STRONG_PASSWORD@localhost/sabar_crm?charset=utf8mb4
Environment=SABAR_CRM_API_KEYS=KEY1:admin,KEY2:system
Environment=SABAR_CRM_DOCS=0
ExecStart=/opt/SOLOMAN-DEV/sabar-mart-crm/.venv/bin/gunicorn sabar_mart_crm.api:app \
    -k uvicorn.workers.UvicornWorker -w 3 -b 127.0.0.1:8000
Restart=always
User=www-data

[Install]
WantedBy=multi-user.target
```
Then run `sudo systemctl enable --now sabar-crm`. Point an nginx `server` block at `proxy_pass http://127.0.0.1:8000;` and add HTTPS with `certbot --nginx`.

Running several workers (`-w 3`) is safe because every request takes the database lock.

---

## How the storage works (and its limits)

- **Tables:** each collection is a `crm_<name>` table. A row stores the record as JSON plus indexed lookup columns. You can browse the tables in phpMyAdmin.
- **One request at a time:** each request takes a database-wide lock (`GET_LOCK`), runs in one transaction and commits before it responds. Only one request runs at a time across all workers. This keeps money and loyalty totals exact; the tests show no lost updates under parallel load. It also caps throughput, which is fine for an internal CRM but not for storefront-scale traffic.
- **Whole-table reports:** analytics, payout reports and recommendations read whole tables, so they slow down as data grows into the hundreds of thousands of rows. Past that point, the next step is moving those reports to SQL aggregate queries.
- **Backups:** use cPanel → **Backup** or `mysqldump`, which cover the database the same way they cover any other.
