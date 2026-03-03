# QZ Tray Receipt Printer Setup

QZ Tray bridges browser WebSocket connections to local USB receipt printers, enabling pick sheet and checkout receipt printing from SnipeScheduler.

## Prerequisites

- **QZ Tray** desktop application (https://qz.io/download/)
- A USB ESC/POS receipt printer (e.g., Epson TM-T88, Star TSP100)
- PHP **OpenSSL extension** enabled on the server (`php -m | grep openssl`)
- SnipeScheduler admin access

## 1. Generate Certificates

QZ Tray requires a signed certificate to trust your SnipeScheduler instance. Generate a self-signed RSA key pair on the server:

```bash
# Create a directory outside the web root
sudo mkdir -p /etc/qz-tray
cd /etc/qz-tray

# Generate private key (2048-bit RSA)
openssl genrsa -out private-key.pem 2048

# Generate self-signed certificate (valid 10 years)
openssl req -x509 -new -key private-key.pem -out digital-certificate.txt -days 3652 \
  -subj "/CN=SnipeScheduler QZ Tray"

# Set permissions (readable by web server user only)
sudo chown www-data:www-data private-key.pem digital-certificate.txt
sudo chmod 600 private-key.pem
sudo chmod 644 digital-certificate.txt
```

> **Important:** Keep the private key outside the web root and restrict permissions. The web server process needs read access to both files.

## 2. Install QZ Tray on Client Machines

QZ Tray must be installed and running on every computer that needs to print receipts.

1. Download QZ Tray from https://qz.io/download/
2. Install and run the application
3. QZ Tray runs in the system tray and listens on `wss://localhost:8181`

### Import the Certificate

Each client machine must trust your certificate:

1. Right-click the QZ Tray system tray icon
2. Select **Advanced** > **Site Manager**
3. Click **+** (Add) and paste the full contents of `digital-certificate.txt`
4. Click **Save**

Alternatively, for enterprise deployments, you can pre-install the certificate via QZ Tray's `override.crt` mechanism. See the [QZ Tray documentation](https://qz.io/wiki/2.0-certificate-setup).

## 3. Configure SnipeScheduler

1. Log in as an admin and go to **Admin > Settings**
2. Find the **QZ Tray (receipt printing)** card
3. Configure:
   - **Enable QZ Tray receipt printing**: Check to enable
   - **Printer name**: The exact OS printer name (see "Finding Your Printer Name" below)
   - **Paper width**: 80mm (48 chars) or 58mm (32 chars) depending on your printer
   - **Certificate path**: Absolute path, e.g. `/etc/qz-tray/digital-certificate.txt`
   - **Private key path**: Absolute path, e.g. `/etc/qz-tray/private-key.pem`
   - **Auto-print checkout receipt**: Optionally auto-print on successful checkout
4. Click **Verify Certificate** to confirm the server can read and sign with the key pair
5. Click **Test Print** to send a test receipt to the printer (requires QZ Tray running on your machine)
6. Click **Save settings**

## 4. Finding Your Printer Name

The printer name must exactly match what the operating system reports.

### Windows
1. Open **Settings > Bluetooth & devices > Printers & scanners**
2. The printer name is shown in the list (e.g., `EPSON TM-T88V Receipt`)

### macOS
1. Open **System Settings > Printers & Scanners**
2. The printer name is shown in the sidebar (e.g., `EPSON_TM_T88V`)

### Linux
```bash
lpstat -p -d
```
The name is the value after `printer` (e.g., `EPSON-TM-T88V`).

## 5. Usage

Once configured, printing features appear on checkout pages for staff:

- **Print Pick Sheet**: Manual button that appears after a successful checkout on both Staff Checkout and Quick Checkout pages
- **Auto-print checkout receipt**: When enabled, a receipt prints automatically after each successful checkout

Both print the same information (checkout details, item list, signature lines) with different titles.

## Troubleshooting

### "Printer service not detected"
- QZ Tray is not running on the client machine
- Start QZ Tray from the applications menu
- Check that it appears in the system tray

### "Certificate not trusted" or signing errors
- The certificate has not been imported into QZ Tray's Site Manager on this machine
- Re-import the certificate following step 2 above

### "Printer not found"
- The printer name in settings does not match the OS printer name exactly
- Check for extra spaces, different capitalization, or encoding differences
- Verify the printer is installed and powered on

### "Private key not found" or "Certificate not found"
- The file paths in settings are incorrect
- Verify the files exist and the web server user has read permission
- Run: `sudo -u www-data test -r /path/to/file && echo OK || echo "Not readable"`

### "Verify Certificate" passes but "Test Print" fails
- Server-side signing works, but QZ Tray cannot be reached from the browser
- Ensure QZ Tray is running and the certificate is imported on the client machine
- Check browser console for WebSocket connection errors

### Prints are garbled or wrong width
- Paper width setting does not match the actual printer paper
- 80mm paper = 48 characters per line
- 58mm paper = 32 characters per line
- Update the paper width setting and test again
