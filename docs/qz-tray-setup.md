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
   - **Connection type**: Choose between Direct USB or OS printer name (see below)
   - **Paper width**: 80mm (48 chars) or 58mm (32 chars) depending on your printer
   - **Certificate path**: Absolute path, e.g. `/etc/qz-tray/digital-certificate.txt`
   - **Private key path**: Absolute path, e.g. `/etc/qz-tray/private-key.pem`
   - **Auto-print checkout receipt**: Optionally auto-print on successful checkout
4. Click **Verify Certificate** to confirm the server can read and sign with the key pair
5. Click **Test Print** to send a test receipt to the printer (requires QZ Tray running on your machine)
6. Click **Save settings**

### Connection Types

#### Direct USB (recommended)

Sends raw ESC/POS commands directly to the USB device, bypassing the OS printer driver. This is the most reliable method for receipt printers.

- Set **Connection type** to **Direct USB (raw)**
- Enter the **USB Vendor ID** and **USB Product ID** in hex format (e.g., `0x04B8` and `0x0202`)
- **USB Interface** and **USB Endpoint** default to `0x00` and `0x01` respectively, which work for most receipt printers. Only change these if your printer uses non-standard values.
- See "Finding USB Vendor/Product IDs" below

#### OS Printer Name

Uses the operating system's installed printer driver. This may work if the printer is shared over a network or the USB IDs are not accessible.

- Set **Connection type** to **OS printer name**
- Enter the exact printer name as it appears in system settings
- See "Finding Your Printer Name" below

## 4. Finding USB Vendor/Product IDs

### Windows
1. Open **Device Manager**
2. Expand **Universal Serial Bus controllers** or **Printers**
3. Right-click the printer device > **Properties** > **Details** tab
4. Select **Hardware Ids** from the dropdown
5. Look for `VID_XXXX&PID_YYYY` — the vendor ID is `0xXXXX`, product ID is `0xYYYY`

Alternatively, from PowerShell:
```powershell
Get-PnpDevice -Class Printer | Get-PnpDeviceProperty -KeyName DEVPKEY_Device_HardwareIds
```

### macOS
```bash
system_profiler SPUSBDataType | grep -A5 -i receipt
```
Look for **Vendor ID** and **Product ID** in the output.

### Linux
```bash
lsusb | grep -i receipt
# or list all USB devices:
lsusb
```
Output format: `Bus 001 Device 005: ID 04b8:0202 Seiko Epson Corp.` — vendor is `0x04B8`, product is `0x0202`.

## 5. Finding Your Printer Name

Only needed if using the **OS printer name** connection type.

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

## 6. Usage

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

### "Printer not found" (OS printer mode)
- The printer name in settings does not match the OS printer name exactly
- Check for extra spaces, different capitalization, or encoding differences
- Verify the printer is installed and powered on
- Consider switching to Direct USB mode instead

### USB device not found (Direct USB mode)
- Verify the vendor/product IDs are correct (see section 4)
- Ensure the printer is plugged in and powered on
- On Linux, the user running QZ Tray may need permission to access USB devices (`udev` rules)

### "USB claim failed" or "Interface not found" (Direct USB mode)
- The USB interface or endpoint values may not match your printer
- Most receipt printers use interface `0x00` and endpoint `0x01` (the defaults)
- If those don't work, check your printer's USB descriptor using `lsusb -v` (Linux) or USB Prober (macOS)
- On Windows, another driver may have exclusive access to the USB device — try closing other printer management software

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
