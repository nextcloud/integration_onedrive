OC.L10N.register(
    "integration_onedrive",
    {
    "Microsoft OneDrive" : "Microsoft OneDrive",
    "Error getting OAuth access token" : "取得 OAuth 存取權杖時發生錯誤",
    "Error during OAuth exchanges" : "OAuth 交換時發生錯誤",
    "OneDrive" : "OneDrive",
    "_%n file was imported from OneDrive storage._::_%n files were imported from OneDrive storage._" : ["從 OneDrive 儲存空間匯入了 %n 個檔案。"],
    "Bad credentials" : "錯誤的憑證",
    "Bad HTTP method" : "錯誤的 HTTP 方法",
    "OAuth access token refused" : "OAuth 存取權杖被拒絕",
    "Microsoft Calendar import" : "匯入 Microsoft 日曆",
    "Connected accounts" : "已連結的帳號",
    "Data migration" : "資料遷移",
    "OneDrive integration" : "OneDrive 整合",
    "Integration of Microsoft OneDrive" : "Microsoft OneDrive 整合",
    "Microsoft OneDrive integration allows you to automatically import your OneDrive files into Nextcloud." : "Microsoft OneDrive 整合讓您自動匯入您的 OneDrive 檔案至 Nextcloud。",
    "Microsoft OneDrive integration" : "Microsoft OneDrive 整合",
    "If you want to allow your Nextcloud users to use OAuth to authenticate to https://onedrive.live.com, create an OAuth application in your Azure settings." : "如果您想要允許您的 Nextcloud 使用者使用 OAuth 對 https://onedrive.live.com 進行身份驗證，在您的 Azure 設定中建立 OAuth 應用程式。",
    "Azure App registrations page" : "Azure 應用程式註冊頁面",
    "Set \"Application name\" to a value that will make sense to your Nextcloud users as they will see it when connecting to OneDrive using your OAuth app." : "將「應用程式名稱」設定為對 Nextcloud 使用者有意義的值，因為他們在使用 OAuth 應用程式連結至 OneDrive 時會看到它。",
    "Make sure you set the \"Redirect URI\" to" : "確保您將「重新導向 URI」設定為",
    "Give the \"Contacts.Read\", \"Calendars.Read\", \"MailboxSettings.Read\", \"Files.Read\" and \"User.Read\" API permission to your app." : "為您的應用程式授予「Contacts.Read」、「Calendars.Read」、「MailboxSettings.Read」與「User.Read」API 權限。",
    "Create a client secret in \"Certificates & secrets\"." : "在「憑證與密碼」中建立一個客戶端密碼。",
    "Put the OAuth app \"Client ID\" and \"Client secret\" below." : "在下方輸入 OAuth 應用程式「客戶端 ID」與「客戶端密碼」。",
    "Your Nextcloud users will then see a \"Connect to OneDrive\" button in their personal settings." : "您的 Nextcloud 使用者將會在他們的個人設定中看到「連結至 OneDrive」按鈕",
    "Client ID" : "客戶端 ID",
    "Client ID of your OneDrive application" : "您的 OneDrive 應用程式客戶端 ID",
    "Client secret" : "客戶端密碼",
    "Client secret of your OneDrive application" : "您 OneDrive 應用程式的客戶端密碼",
    "Use a popup to authenticate" : "使用彈出式視窗進行驗證",
    "OneDrive admin options saved" : "已儲存 OneDrive 管理員選項",
    "Failed to save OneDrive admin options" : "儲存 OneDrive 管理員選項失敗",
    "Enable navigation link" : "啟用導覽連結",
    "Ask your Nextcloud administrator to configure OneDrive OAuth settings in order to use this integration." : "要求您的 Nextcloud 管理員設定 OneDrive OAuth 設定以使用此整合。",
    "Connect to OneDrive" : "連結至 OneDrive",
    "Connected as {user}" : "以 {user} 身份連線",
    "Disconnect from OneDrive" : "與 OneDrive 斷開連結",
    "Onedrive storage" : "OneDrive 儲存空間",
    "Import directory" : "匯入目錄",
    "Onedrive storage ({formSize})" : "OneDrive 儲存空間（{formSize}）",
    "Import Onedrive files" : "匯入 OneDrive 檔案",
    "Your Onedrive storage is bigger than your remaining space left ({formSpace})" : "您的 OneDrive 儲存空間大於您的剩餘空間 ({formSpace})",
    "Cancel Onedrive files import" : "取消 OneDrive 檔案匯入",
    "Contacts" : "聯絡人",
    "{amount} contacts" : "{amount} 位聯絡人",
    "Import Contacts in Nextcloud" : "將聯絡人匯入至 Nextcloud",
    "Calendars" : "日曆",
    "Import calendar" : "匯入日曆",
    "Import job is currently running" : "匯入作業正在執行",
    "Import job is scheduled" : "已排程匯入作業",
    "Onedrive import process will begin soon" : "OneDrive 匯入流程即將開始",
    "Connected to OneDrive!" : "連結至 OneDrive！",
    "OneDrive OAuth error:" : "OneDrive OAuth 錯誤：",
    "OneDrive options saved" : "已儲存 OneDrive 選項",
    "Failed to save OneDrive options" : "儲存 OneDrive 選項失敗",
    "Sign in with OneDrive" : "使用 OneDrive 登入",
    "Failed to save OneDrive OAuth state" : "儲存 OneDrive OAuth 狀態失敗",
    "Failed to get OneDrive storage information" : "取得 OneDrive 儲存空間資訊失敗",
    "Starting importing files in {targetPath} directory" : "開始匯入檔案至 {targetPath} 目錄",
    "Failed to start importing Onedrive storage" : "無法開始匯入 OneDrive 儲存空間",
    "Failed to get calendar list" : "取得日曆清單失敗",
    "Failed to import calendar" : "匯入日曆失敗",
    "Failed to get number of contacts" : "無法取得聯絡人數量",
    "Failed to get address book list" : "取得通訊錄清單失敗",
    "Choose where to write imported files" : "選擇要寫入匯入檔案的位置",
    "_{amount} file imported ({formImported}) ({progress}%)_::_{amount} files imported ({formImported}) ({progress}%)_" : ["匯入了 {amount} 個檔案（{formImported}）（{progress}%）"],
    "_{number} event successfully imported in {name}_::_{number} events successfully imported in {name}_" : ["{number} 事件成功匯入至 {name}"],
    "_{nbAdded} contact created, {nbUpdated} updated, {nbSkipped} skipped, {nbFailed} failed_::_{nbAdded} contacts created, {nbUpdated} updated, {nbSkipped} skipped, {nbFailed} failed_" : ["建立了 {nbAdded} 個聯絡人、已更新 {nbUpdated} 個、略過 {nbSkipped} 個、{nbFailed} 個失敗"],
    "Last Onedrive import job at {date}" : "上次 OneDrive 匯入作業執行於 {date}"
},
"nplurals=1; plural=0;");
