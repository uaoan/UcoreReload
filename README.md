# UcoreReload

<p align="center">
  <b>Android no-install APK hot update / hot patch SDK</b><br/>
  Load code, resources, layouts, drawables and plugin Activities from a downloaded APK without triggering Android's system installer.
</p>

<p align="center">
  🌐 <a href="#中文">中文</a> | <a href="#english">English</a><br/>
  Backend / 官网: <a href="http://ucore.uaoan.cn/">http://ucore.uaoan.cn/</a><br/>
  Demo APK: <a href="https://github.com/uaoan/UcoreReload/raw/main/ucore.apk">Download ucore.apk</a>
</p>

---

<a id="中文"></a>

## 中文

### 项目介绍

**UcoreReload** 是一个 Android 免安装 APK 热更新开源库。它的核心目标是让宿主 App 在启动后自动连接后端，检测新的热更新版本，下载完整 APK 文件到本地，然后在不调用系统安装器的情况下，优先加载下载 APK 中的 Java 代码、`res/layout`、`res/values`、`drawable`、`mipmap`、`assets` 等资源。

> **必须说明：本库必须配合 UcoreReload 后端官网使用：**  
> **http://ucore.uaoan.cn/**  
> 你需要在后端官网创建 App，获取 AppID，上传并发布热更新 APK，客户端库会通过固定接口 `http://ucore.uaoan.cn/api.php` 检查更新。

### 功能特性

- 自动检查后端热更新版本。
- 自动下载完整 APK 补丁。
- 支持 SHA-256 校验补丁文件完整性。
- 支持重启后优先加载补丁 APK 中的同名 Java 类。
- 支持加载补丁 APK 中的 `res/layout`、`res/values`、`drawable`、`mipmap`、`assets`。
- 支持新增 Activity 的代理启动：补丁 APK 中新增但宿主 Manifest 未注册的 Activity，会通过 `UcoreStubActivity` 代理打开。
- 支持 `Application.attachBaseContext()` 早期 Dex 合并。
- 支持调试日志：`UcoreReload.getDebugLog()`、`UcoreReload.dumpDebugInfo(context)`。
- 支持 Java / Kotlin 项目调用。

### 热更新 Demo 预览

点击下载热更新预览 Demo APK：

```text
https://github.com/uaoan/UcoreReload/raw/main/ucore.apk
```

### 添加远程依赖

在项目根目录 `settings.gradle` 或 `settings.gradle.kts` 中添加 JitPack 仓库。

#### Groovy / settings.gradle

```gradle
dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        mavenCentral()
        maven { url 'https://jitpack.io' }
    }
}
```

Android 项目通常还需要保留 `google()`：

```gradle
dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        mavenCentral()
        maven { url 'https://jitpack.io' }
    }
}
```

然后在 App 模块 `build.gradle` 中添加：

```gradle
dependencies {
    implementation 'com.github.uaoan:UcoreReload:1.0.0'
}
```

### 后端准备

1. 打开后端官网：`http://ucore.uaoan.cn/`。
2. 注册并登录账号。
3. 创建一个 App。
4. 复制后台生成的 `AppID`。
5. 上传新的 APK 热更新版本并发布。
6. 客户端填写这个 `AppID`，启动后会自动检查并下载最新热更新 APK。

### 快速接入

#### 1. 配置 AndroidManifest.xml

```xml
<manifest xmlns:android="http://schemas.android.com/apk/res/android">

    <!-- 访问官方后端接口和下载热更新 APK 需要网络权限 -->
    <uses-permission android:name="android.permission.INTERNET" />

    <application
        android:name=".UcoreApp"
        android:usesCleartextTraffic="true">

        <!-- 用于代理打开补丁 APK 里新增的 Activity -->
        <activity
            android:name="com.uaoan.ucorereload.sdk.UcoreStubActivity"
            android:exported="false" />

        <activity
            android:name=".MainActivity"
            android:exported="true">
            <intent-filter>
                <action android:name="android.intent.action.MAIN" />
                <category android:name="android.intent.category.LAUNCHER" />
            </intent-filter>
        </activity>
    </application>
</manifest>
```

#### 2. 新建 Application

```java
package com.example.app;

import android.app.Application;
import android.content.Context;

import com.uaoan.ucorereload.sdk.UcoreReload;

public class UcoreApp extends Application {

    /**
     * 在 http://ucore.uaoan.cn/ 后台创建 App 后复制这里的 AppID。
     * 注意：只填写 AppID，不需要填写接口地址。
     * UcoreReload 默认固定使用 http://ucore.uaoan.cn/api.php。
     */
    private static final String UCORE_APP_ID = "请填写你的AppID";

    @Override
    protected void attachBaseContext(Context base) {
        super.attachBaseContext(base);

        // 必须尽早调用：让已下载的补丁 APK 在启动阶段优先合并 Dex。
        UcoreReload.installSavedPatchEarly(base);
    }

    @Override
    public void onCreate() {
        super.onCreate();

        // 自动热更新入口：检查后端版本 → 下载补丁 APK → 保存 → 需要时自动重启。
        UcoreReload.installInApplication(this, UCORE_APP_ID);
    }
}
```

#### 3. Activity 继承 UcoreActivity

```java
package com.example.app;

import android.os.Bundle;
import com.uaoan.ucorereload.sdk.UcoreActivity;

public class MainActivity extends UcoreActivity {
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        // 正常写自己的布局即可。
        // 当本地已经有热更新 APK 时，R.layout.activity_main 会优先映射到补丁 APK 里的同名 layout。
        setContentView(R.layout.activity_main);
    }
}
```

#### 4. 手动检查更新，可选

一般情况下 `Application` 中的 `installInApplication()` 已经会自动检查更新。需要手动触发时可使用：

```java
import com.uaoan.ucorereload.sdk.SimpleUcoreReloadListener;
import com.uaoan.ucorereload.sdk.UcoreReload;
import com.uaoan.ucorereload.sdk.UpdateInfo;
import com.uaoan.ucorereload.sdk.PatchHandle;

UcoreReload.checkAndApplyByAppId(this, "你的AppID", new SimpleUcoreReloadListener() {
    @Override
    public void onDownloadProgress(int percent, long downloadedBytes, long totalBytes) {
        // 下载进度，percent 为 0 - 100。
    }

    @Override
    public void onPatchLoaded(UpdateInfo info, PatchHandle handle) {
        // 补丁 APK 已加载，重启后通常会完整生效。
    }

    @Override
    public void onNeedRestart(UpdateInfo info) {
        // 需要重启 App，让新 Dex / 新资源完整生效。
        UcoreReload.restartApp(getApplicationContext());
    }

    @Override
    public void onError(Throwable throwable) {
        // 检查更新、下载、校验或加载失败。
    }
});
```

#### 5. Kotlin 调用示例

```kotlin
class UcoreApp : Application() {
    override fun attachBaseContext(base: Context) {
        super.attachBaseContext(base)
        // 尽早加载本地已经保存的补丁 APK。
        UcoreReload.installSavedPatchEarly(base)
    }

    override fun onCreate() {
        super.onCreate()
        // 后台 AppID，只填 AppID，不填完整 URL。
        UcoreReload.installInApplication(this, "请填写你的AppID")
    }
}
```

### 调试与排查

```java
// 开关内部调试日志。
UcoreReload.setDebugLogEnabled(true);

// 获取最近的调试日志。
String log = UcoreReload.getDebugLog();

// 输出当前补丁状态、资源状态、代理 Activity 状态。
String info = UcoreReload.dumpDebugInfo(context);

// 清除本地热更新 APK。
UcoreReload.clearPatch(context);

// 重启 App。
UcoreReload.restartApp(context);
```

### 补丁 APK 开发注意事项

- 宿主启动 Activity 建议继承 `UcoreActivity`。
- 需要热更新资源的页面，也建议继承 `UcoreActivity`。
- 修改同名 Activity、同名 layout、同名 drawable、同名 string 等资源时，重启后会优先使用补丁 APK 中的内容。
- 新增 Activity 可以通过库里的 `UcoreStubActivity` 代理打开，但新增 `Service`、`BroadcastReceiver`、`ContentProvider`、新权限、新 intent-filter 无法在不安装 APK 的情况下真正注册到系统。
- 普通 Android App 不能真正物理替换系统已安装的 `base.apk`；本项目实现的是“下载 APK → 本地动态加载代码与资源”。

---

<a id="english"></a>

## English

### About

**UcoreReload** is an open-source Android no-install APK hot update SDK. It allows a host app to check a backend service, download a full APK patch, and load code plus resources from that APK without launching Android's system installer.

> **Required backend:** this SDK must work with the official UcoreReload backend website:  
> **http://ucore.uaoan.cn/**  
> Create an App on the backend, copy the AppID, upload and publish a patch APK. The SDK checks updates through the fixed API endpoint `http://ucore.uaoan.cn/api.php`.

### Features

- Auto check remote patch versions.
- Auto download full APK patches.
- SHA-256 validation for patch APK files.
- Load Java classes from the downloaded APK after restart.
- Load `res/layout`, `res/values`, `drawable`, `mipmap`, and `assets` from the downloaded APK.
- Proxy newly added patch Activities through `UcoreStubActivity`.
- Early Dex merge in `Application.attachBaseContext()`.
- Debug helpers: `UcoreReload.getDebugLog()` and `UcoreReload.dumpDebugInfo(context)`.
- Works in Java and Kotlin Android projects.

### Demo APK

Download the hot update preview demo APK:

```text
https://github.com/uaoan/UcoreReload/raw/main/ucore.apk
```

### Dependency

Add JitPack to your root `settings.gradle`:

```gradle
dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        mavenCentral()
        maven { url 'https://jitpack.io' }
    }
}
```

For Android projects, usually keep `google()` as well:

```gradle
dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        mavenCentral()
        maven { url 'https://jitpack.io' }
    }
}
```

Then add the library dependency:

```gradle
dependencies {
    implementation 'com.github.uaoan:UcoreReload:1.0.0'
}
```

### Backend Setup

1. Open `http://ucore.uaoan.cn/`.
2. Register and sign in.
3. Create an App.
4. Copy the generated `AppID`.
5. Upload and publish a new APK patch version.
6. Put the AppID in your Android project. The SDK will auto check and download the latest patch APK.

### Quick Start

#### 1. AndroidManifest.xml

```xml
<manifest xmlns:android="http://schemas.android.com/apk/res/android">

    <!-- Required for checking backend API and downloading patch APK files. -->
    <uses-permission android:name="android.permission.INTERNET" />

    <application
        android:name=".UcoreApp"
        android:usesCleartextTraffic="true">

        <!-- Stub Activity used to proxy Activities newly added in the patch APK. -->
        <activity
            android:name="com.uaoan.ucorereload.sdk.UcoreStubActivity"
            android:exported="false" />

        <activity
            android:name=".MainActivity"
            android:exported="true">
            <intent-filter>
                <action android:name="android.intent.action.MAIN" />
                <category android:name="android.intent.category.LAUNCHER" />
            </intent-filter>
        </activity>
    </application>
</manifest>
```

#### 2. Application

```java
package com.example.app;

import android.app.Application;
import android.content.Context;

import com.uaoan.ucorereload.sdk.UcoreReload;

public class UcoreApp extends Application {

    /**
     * Copy the AppID from http://ucore.uaoan.cn/ after creating an App.
     * Only put the AppID here, not a full API URL.
     * UcoreReload uses http://ucore.uaoan.cn/api.php by default.
     */
    private static final String UCORE_APP_ID = "YOUR_APP_ID";

    @Override
    protected void attachBaseContext(Context base) {
        super.attachBaseContext(base);

        // Call as early as possible to merge the saved patch APK Dex at startup.
        UcoreReload.installSavedPatchEarly(base);
    }

    @Override
    public void onCreate() {
        super.onCreate();

        // Auto update flow: check backend → download patch APK → save → restart if needed.
        UcoreReload.installInApplication(this, UCORE_APP_ID);
    }
}
```

#### 3. Activity

```java
package com.example.app;

import android.os.Bundle;
import com.uaoan.ucorereload.sdk.UcoreActivity;

public class MainActivity extends UcoreActivity {
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        // Use your layout normally.
        // When a patch APK exists, this layout will be mapped to the same-name layout inside the patch APK.
        setContentView(R.layout.activity_main);
    }
}
```

#### 4. Optional Manual Check

`installInApplication()` already checks updates automatically. If you still need manual checking:

```java
UcoreReload.checkAndApplyByAppId(this, "YOUR_APP_ID", new SimpleUcoreReloadListener() {
    @Override
    public void onDownloadProgress(int percent, long downloadedBytes, long totalBytes) {
        // Download progress from 0 to 100.
    }

    @Override
    public void onPatchLoaded(UpdateInfo info, PatchHandle handle) {
        // Patch APK has been loaded. A restart is usually required for full effect.
    }

    @Override
    public void onNeedRestart(UpdateInfo info) {
        // Restart the app to apply new Dex and resources completely.
        UcoreReload.restartApp(getApplicationContext());
    }

    @Override
    public void onError(Throwable throwable) {
        // Update check, download, validation, or loading failed.
    }
});
```

### Debug

```java
UcoreReload.setDebugLogEnabled(true);
String log = UcoreReload.getDebugLog();
String info = UcoreReload.dumpDebugInfo(context);
UcoreReload.clearPatch(context);
UcoreReload.restartApp(context);
```

### Notes and Limitations

- Activities that need resource hot update should extend `UcoreActivity`.
- Same-name Activity classes and resources can be loaded from the patch APK after restart.
- Newly added patch Activities can be proxied by `UcoreStubActivity`.
- Newly added `Service`, `BroadcastReceiver`, `ContentProvider`, permissions, or intent-filters cannot be truly registered without installing the APK.
- Android apps cannot physically replace the installed `base.apk` without installation. UcoreReload implements local dynamic loading from a downloaded APK.

