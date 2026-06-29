package com.uaoan.ucorereload.sdk;

import android.app.Activity;
import android.app.Application;
import android.app.Instrumentation;
import android.app.ActivityManager;
import android.content.Context;
import android.content.Intent;
import android.content.ComponentName;
import android.content.ContextWrapper;
import android.content.SharedPreferences;
import android.content.pm.ActivityInfo;
import android.content.pm.ApplicationInfo;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.content.res.AssetManager;
import android.content.res.Resources;
import android.os.Build;
import android.os.Handler;
import android.os.Bundle;
import android.os.Looper;
import android.text.TextUtils;
import android.util.Log;


import java.io.BufferedInputStream;
import java.io.BufferedOutputStream;
import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.lang.reflect.Array;
import java.lang.reflect.Field;
import java.lang.reflect.Method;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLEncoder;
import java.security.MessageDigest;
import java.util.Locale;
import java.util.Enumeration;
import java.util.HashMap;
import java.util.Map;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.zip.ZipEntry;
import java.util.zip.ZipFile;

import dalvik.system.BaseDexClassLoader;
import dalvik.system.DexClassLoader;

public final class UcoreReload {
    private static final String PREF = "ucore_reload_hot_patch";
    private static final String KEY_PATCH_FILE = "patch_file";
    private static final String KEY_PATCH_INFO = "patch_info";
    private static final String KEY_PATCH_CODE = "patch_code";
    private static final String KEY_DEFAULT_ENTRY_CLASS = "default_entry_class";
    private static final String KEY_LAST_RESTART_CODE = "last_restart_code";
    private static final String KEY_LAUNCHING_PATCH_CODE = "launching_patch_code";
    private static final String KEY_LAUNCH_OK_CODE = "launch_ok_code";
    private static final String KEY_DISABLED_PATCH_CODE = "disabled_patch_code";
    static final String EXTRA_TARGET_ACTIVITY = "ucore_reload_target_activity";
    static final String EXTRA_ORIGINAL_COMPONENT = "ucore_reload_original_component";
    private static final int CONNECT_TIMEOUT = 12000;
    private static final int READ_TIMEOUT = 30000;
    /**
     * 固定热更新服务域名：库只允许通过这个域名检查热更新。
     * App 端只需要填写后台创建 App 后得到的 AppID。
     */
    public static final String FIXED_UPDATE_DOMAIN = "http://ucore.uaoan.cn";
    public static final String FIXED_UPDATE_API = FIXED_UPDATE_DOMAIN + "/api.php";

    private static final Object LOCK = new Object();
    private static volatile PatchHandle currentPatch;
    private static volatile boolean instrumentationInstalled;
    private static volatile String defaultPluginEntryClass;
    private static volatile boolean autoChecking;
    private static volatile String mergedPatchPath;
    private static volatile boolean debugLogEnabled = true;
    private static final StringBuilder DEBUG_LOG = new StringBuilder(8192);
    private static final int MAX_DEBUG_LOG_CHARS = 12000;
    // 检查更新只允许固定域名 http://ucore.uaoan.cn。

    private UcoreReload() {
    }

    /**
     * 兼容旧版本 API。
     */
    public static void setAllowHttpForDebug(boolean allow) {
        // no-op: HTTP is always allowed by this library.
    }


    /** 开启/关闭 UcoreReload 内部调试日志。默认开启，会同时输出到 Logcat。 */
    public static void setDebugLogEnabled(boolean enabled) {
        debugLogEnabled = enabled;
    }

    /** 手动写入一条调试日志，iApp 或第三方工具里也可以直接调用。 */
    public static void logDebug(String message) {
        debug("APP", String.valueOf(message), null);
    }

    /** 清空内存中的调试日志。 */
    public static void clearDebugLog() {
        synchronized (DEBUG_LOG) {
            DEBUG_LOG.setLength(0);
        }
    }

    /** 获取内存调试日志，可在界面上显示或复制发给我排查。 */
    public static String getDebugLog() {
        synchronized (DEBUG_LOG) {
            return DEBUG_LOG.toString();
        }
    }

    /** 输出当前补丁、Activity 代理、资源加载状态。 */
    public static String dumpDebugInfo(Context context) {
        StringBuilder sb = new StringBuilder();
        sb.append("UcoreReload Debug Info\n");
        sb.append("fixedApi=").append(FIXED_UPDATE_API).append('\n');
        sb.append("instrumentationInstalled=").append(instrumentationInstalled).append('\n');
        sb.append("mergedPatchPath=").append(mergedPatchPath).append('\n');
        try {
            sb.append("package=").append(context == null ? "null" : context.getPackageName()).append('\n');
        } catch (Throwable ignored) {}
        PatchHandle handle = currentPatch;
        sb.append("currentPatch=").append(handle != null).append('\n');
        if (handle != null) {
            sb.append("patchFile=").append(handle.getPatchFile()).append('\n');
            sb.append("patchPackage=").append(handle.getPackageName()).append('\n');
            sb.append("hasResources=").append(handle.getResources() != null).append('\n');
            sb.append("hasClassLoader=").append(handle.getClassLoader() != null).append('\n');
            if (handle.getUpdateInfo() != null) {
                sb.append("patchCode=").append(handle.getUpdateInfo().getPatchCode()).append('\n');
                sb.append("patchName=").append(handle.getUpdateInfo().getPatchName()).append('\n');
            }
        }
        if (context != null) {
            try {
                ComponentName proxy = chooseProxyActivityComponent(context);
                sb.append("proxyActivity=").append(proxy == null ? "null" : proxy.flattenToString()).append('\n');
            } catch (Throwable t) {
                sb.append("proxyActivityError=").append(t.getClass().getName()).append(':').append(t.getMessage()).append('\n');
            }
        }
        sb.append("\nRecent Logs:\n").append(getDebugLog());
        return sb.toString();
    }

    private static void debug(String tag, String msg, Throwable throwable) {
        if (!debugLogEnabled) {
            return;
        }
        String line = System.currentTimeMillis() + " [" + tag + "] " + msg;
        synchronized (DEBUG_LOG) {
            DEBUG_LOG.append(line).append('\n');
            if (throwable != null) {
                DEBUG_LOG.append(throwable.getClass().getName()).append(": ").append(throwable.getMessage()).append('\n');
            }
            if (DEBUG_LOG.length() > MAX_DEBUG_LOG_CHARS) {
                DEBUG_LOG.delete(0, DEBUG_LOG.length() - MAX_DEBUG_LOG_CHARS);
            }
        }
        if (throwable == null) {
            Log.d("UcoreReload", msg);
        } else {
            Log.w("UcoreReload", msg, throwable);
        }
    }

    /**
     * Application.attachBaseContext 里尽早调用。它会尝试把已经保存的补丁 dex 放到宿主 dexElements 前面。
     * 已经被系统加载过的类不会被替换，通常需要杀进程重启后才会完整生效。
     */
    public static boolean installSavedPatchEarly(Context context) {
        try {
            if (context == null) {
                return false;
            }
            Context appContext = context.getApplicationContext();
            if (appContext == null) {
                // iApp / 部分第三方打包工具在 attachBaseContext 里传入的 Context，
                // getApplicationContext() 可能暂时为 null。这里必须回退到 base Context，
                // 否则早期 dex 合并失败，后续新增 Activity 无法从补丁 APK 加载。
                appContext = context;
            }
            PatchHandle handle = loadSavedPatch(appContext);
            if (handle == null) {
                return false;
            }
            int code = handle.getUpdateInfo() == null ? getAppliedPatchCode(appContext) : handle.getUpdateInfo().getPatchCode();
            if (!preparePatchLaunchOrDisableIfCrashed(appContext, code)) {
                return false;
            }

            // 最早期把补丁 APK 的 dexElements 合并到宿主 PathClassLoader 前面。
            // 这样系统仍然按 AndroidManifest.xml 创建启动 Activity，
            // 但同名 MainActivity / R / 普通业务类会优先来自下载后的 APK。
            // 不再使用自定义 Instrumentation 强行 newActivity，避免部分机型启动闪退。
            forceLoadHostSdkClasses(appContext);
            // 不再把补丁 dexElements 合并进宿主 PathClassLoader。
            // 合并模式会让补丁新增的三方库/AAR 与宿主类空间混在一起，
            // 宿主缺依赖或版本不一致时容易 ClassNotFound/NoSuchMethod/UnsatisfiedLinkError。
            // 统一让补丁代码、补丁依赖、补丁 Activity 都通过独立的 ChildFirstDexClassLoader 加载。
            installInstrumentationHook(appContext);
            return true;
        } catch (Throwable throwable) {
            debug("EARLY", "install saved patch early failed, patch disabled for safety", throwable);
            try {
                clearPatch(context);
            } catch (Throwable ignored) {
            }
            return false;
        }
    }

    /**
     * Application.onCreate 或首页里调用，用于恢复已安装补丁的 ClassLoader 与 Resources。
     */
    public static PatchHandle loadSavedPatch(Context context) throws UcoreReloadException {
        SharedPreferences sp = prefs(context);
        String path = sp.getString(KEY_PATCH_FILE, "");
        String json = sp.getString(KEY_PATCH_INFO, "");
        if (TextUtils.isEmpty(path) || TextUtils.isEmpty(json)) {
            return null;
        }
        File file = new File(path);
        if (!file.exists()) {
            clearPatch(context);
            return null;
        }
        try {
            UpdateInfo info = UpdateInfo.fromJson(json);
            return loadPatch(context, file, info, false);
        } catch (Exception e) {
            throw new UcoreReloadException("加载本地热更新补丁失败", e);
        }
    }

    public static PatchHandle getCurrentPatch() {
        return currentPatch;
    }

    public static boolean hasPatch() {
        return currentPatch != null;
    }

    public static void clearPatch(Context context) {
        synchronized (LOCK) {
            SharedPreferences sp = prefs(context);
            String path = sp.getString(KEY_PATCH_FILE, "");
            sp.edit().clear().commit();
            currentPatch = null;
            if (!TextUtils.isEmpty(path)) {
                File file = new File(path);
                //noinspection ResultOfMethodCallIgnored
                file.delete();
            }
        }
    }

    public static int getAppliedPatchCode(Context context) {
        return prefs(context).getInt(KEY_PATCH_CODE, 0);
    }

    /**
     * 根据后台创建 App 后得到的 AppID 生成固定更新接口地址。
     * 域名固定为 http://ucore.uaoan.cn，外部不能传入其它域名。
     */
    public static String buildFixedUpdateApiUrl(String appId) {
        String id = appId == null ? "" : appId.trim();
        String url = FIXED_UPDATE_API + "?action=check_update";
        if (id.length() == 0) {
            return url;
        }
        try {
            return url + "&appId=" + URLEncoder.encode(id, "UTF-8");
        } catch (Exception ignored) {
            return url + "&appId=" + id;
        }
    }

    public static void checkAndApplyByAppId(final Context context, final String appId, final UcoreReloadListener listener) {
        checkAndApply(context, buildFixedUpdateApiUrl(appId), listener);
    }

    private static boolean isFixedUpdateApiUrl(String apiUrl) {
        if (apiUrl == null) {
            return false;
        }
        try {
            URL u = new URL(apiUrl);
            return "http".equalsIgnoreCase(u.getProtocol())
                    && "ucore.uaoan.cn".equalsIgnoreCase(u.getHost())
                    && (u.getPath() == null || u.getPath().equals("/api.php") || u.getPath().endsWith("/api.php"));
        } catch (Throwable ignored) {
            return false;
        }
    }

    /**
     * 后台检查、下载并加载热更新补丁。api.php 返回 UpdateInfo JSON。
     */
    public static void checkAndApply(final Context context, final String apiUrl, final UcoreReloadListener listener) {
        final Context appContext = getAppContext(context);
        final ListenerDispatcher dispatcher = new ListenerDispatcher(listener);
        dispatcher.onCheckStart();
        new Thread(new Runnable() {
            @Override
            public void run() {
                try {
                    if (TextUtils.isEmpty(apiUrl)) {
                        throw new UcoreReloadException("更新接口不能为空");
                    }
                    if (!isFixedUpdateApiUrl(apiUrl)) {
                        throw new UcoreReloadException("更新接口只能使用固定域名：" + FIXED_UPDATE_API + "。App 端请填写后台创建 App 后得到的 AppID。");
                    }
                    String json = getText(apiUrl);
                    UpdateInfo info = UpdateInfo.fromJson(json);
                    dispatcher.onPatchInfo(info);

                    String unavailable = checkPatchAvailable(appContext, info);
                    if (unavailable != null) {
                        dispatcher.onNoPatch(unavailable);
                        return;
                    }

                    int appliedCode = getAppliedPatchCode(appContext);
                    if (info.getPatchCode() > 0 && info.getPatchCode() <= appliedCode) {
                        PatchHandle saved = loadSavedPatch(appContext);
                        if (saved != null) {
                            dispatcher.onPatchLoaded(info, saved);
                            return;
                        }
                        // 记录还在但补丁文件丢了，清理后重新下载当前版本。
                        prefs(appContext).edit().remove(KEY_PATCH_CODE).remove(KEY_PATCH_FILE).remove(KEY_PATCH_INFO).commit();
                    }

                    URL patchUrl = buildUrl(apiUrl, info.getPatchUrl());
                    File patchFile = downloadPatch(appContext, patchUrl, info, dispatcher);
                    dispatcher.onDownloaded(patchFile);
                    PatchHandle handle = loadPatch(appContext, patchFile, info, true);
                    dispatcher.onPatchLoaded(info, handle);
                    if (info.isRestartAfterApply()) {
                        dispatcher.onNeedRestart(info);
                    }
                } catch (Throwable throwable) {
                    dispatcher.onError(throwable);
                }
            }
        }, "ucore-reload-check").start();
    }

    /**
     * 直接加载一个本地 apk/jar/dex/zip 补丁。apk 可同时携带 classes.dex 与 res/resources.arsc。
     */
    public static PatchHandle applyLocalPatch(Context context, File patchFile, UpdateInfo info) throws UcoreReloadException {
        try {
            return loadPatch(getAppContext(context), patchFile, info, true);
        } catch (Exception e) {
            throw new UcoreReloadException("应用本地热更新补丁失败", e);
        }
    }

    public static Class<?> loadClass(String className) throws ClassNotFoundException {
        PatchHandle handle = currentPatch;
        if (handle == null) {
            throw new ClassNotFoundException("当前没有已加载补丁：" + className);
        }
        return handle.loadClass(className);
    }

    public static Object newInstance(String className) throws Exception {
        PatchHandle handle = currentPatch;
        if (handle == null) {
            throw new UcoreReloadException("当前没有已加载补丁：" + className);
        }
        return handle.newInstance(className);
    }

    public static Object invokeStatic(String className, String methodName, Class<?>[] parameterTypes, Object... args) throws Exception {
        Class<?> clazz = loadClass(className);
        Method method = clazz.getDeclaredMethod(methodName, parameterTypes == null ? new Class<?>[0] : parameterTypes);
        method.setAccessible(true);
        return method.invoke(null, args == null ? new Object[0] : args);
    }

    public static int getPatchIdentifier(String name, String defType) {
        PatchHandle handle = currentPatch;
        return handle == null ? 0 : handle.getIdentifier(name, defType);
    }

    public static String getPatchString(String name, String fallback) {
        PatchHandle handle = currentPatch;
        return handle == null ? fallback : handle.getString(name, fallback);
    }

    public static Resources getPatchResources() {
        PatchHandle handle = currentPatch;
        return handle == null ? null : handle.getResources();
    }

    public static Context getPatchContext() {
        PatchHandle handle = currentPatch;
        return handle == null ? null : handle.getPatchContext();
    }


    /**
     * 用补丁 APK 里的 layout 直接替换 Activity 页面。
     * 这用于“上传完整 APK 后只改了 res/layout/activity_main.xml，希望重启后页面布局也变化”的场景。
     * 注意：这是把补丁 resources.arsc 里的布局 inflate 出来显示，并不是系统层面替换已安装 APK。
     * 新增出来的按钮会显示；如果需要点击事件，宿主代码需要通过资源名重新绑定，或在补丁入口类里处理。
     */
    public static android.view.View setPatchContentView(Activity activity, String layoutName) {
        PatchHandle handle = currentPatch;
        if (activity == null || handle == null || TextUtils.isEmpty(layoutName)) {
            return null;
        }
        android.view.View view = handle.inflateLayout(activity, layoutName, null, false);
        if (view != null) {
            activity.setContentView(view);
        }
        return view;
    }


    /**
     * 推荐在 Application.onCreate 中调用。
     *
     * 这里的第二个参数不是 URL，而是后台创建 App 后得到的 AppID。
     * 库内部会固定请求 http://ucore.uaoan.cn/api.php?action=check_update&appId=你的AppID，
     * 不允许 App 侧传入其它热更新域名。
     */
    public static void installInApplication(final Application application, final String appId) {
        if (application == null) {
            return;
        }
        try {
            PatchHandle saved = loadSavedPatch(application);
            // iApp / 部分第三方工具中 attachBaseContext 早期阶段可能拿不到完整 ApplicationContext。
            // 这里仅恢复 currentPatch 与补丁资源/类加载器；不再合并 dex 到宿主 ClassLoader。
            if (saved != null && saved.getPatchFile() != null) {
                forceLoadHostSdkClasses(application);
            }
        } catch (Throwable throwable) {
            Log.w("UcoreReload", "load saved patch failed", throwable);
            debug("APP", "load saved patch in application failed", throwable);
        }
        // 这里安装 Activity 代理 Hook：未注册在宿主 Manifest 的补丁 Activity 会自动走 UcoreStubActivity。
        installInstrumentationHook(application);
        registerActivityResourceHook(application);
        markPatchLaunchOkLater(application);
        autoCheckAndApply(application, buildFixedUpdateApiUrl(appId));
    }

    /** 兼容旧调用。pluginEntryClass 参数已不再作为默认入口使用，启动入口以 AndroidManifest.xml 为准。 */
    public static void installInApplication(final Application application, final String appId, final String pluginEntryClass) {
        installInApplication(application, appId);
    }

    public static void setDefaultPluginEntryClass(Context context, String pluginEntryClass) {
        if (context == null) {
            return;
        }
        if (TextUtils.isEmpty(pluginEntryClass)) {
            pluginEntryClass = context.getPackageName() + ".MainActivity";
        }
        defaultPluginEntryClass = pluginEntryClass;
        prefs(context).edit().putString(KEY_DEFAULT_ENTRY_CLASS, pluginEntryClass).commit();
    }

    public static String getDefaultPluginEntryClass(Context context) {
        if (!TextUtils.isEmpty(defaultPluginEntryClass)) {
            return defaultPluginEntryClass;
        }
        String fallback = context == null ? "" : context.getPackageName() + ".MainActivity";
        if (context == null) {
            return fallback;
        }
        String saved = prefs(context).getString(KEY_DEFAULT_ENTRY_CLASS, fallback);
        defaultPluginEntryClass = saved;
        return saved;
    }

    /**
     * Application 自动检查更新。
     * 修复点：
     * 1. patchCode 必须大于 0，避免后台版本号为 0 时每次启动都下载/重启；
     * 2. 保存补丁信息使用 commit()，避免 apply() 未落盘就杀进程导致重启循环；
     * 3. 同一个 patchCode 只自动重启一次。
     */
    public static void autoCheckAndApply(final Application application, final String apiUrl) {
        if (application == null || TextUtils.isEmpty(apiUrl) || !isMainProcess(application)) {
            return;
        }
        final SharedPreferences sp = prefs(application);
        if (autoChecking) {
            return;
        }
        autoChecking = true;
        checkAndApply(application, apiUrl, new SimpleUcoreReloadListener() {
            private boolean downloaded;

            @Override public void onDownloaded(File patchFile) { downloaded = true; }

            @Override
            public void onPatchLoaded(UpdateInfo info, PatchHandle handle) {
                autoChecking = false;
                if (downloaded && info != null && info.isAutoApply()) {
                    int code = info.getPatchCode();
                    int lastRestartCode = sp.getInt(KEY_LAST_RESTART_CODE, 0);
                    if (code > 0 && code != lastRestartCode) {
                        sp.edit().putInt(KEY_LAST_RESTART_CODE, code).commit();
                        restartApp(application);
                    }
                }
            }

            @Override public void onNoPatch(String reason) { autoChecking = false; }

            @Override
            public void onError(Throwable throwable) {
                autoChecking = false;
                Log.w("UcoreReload", "auto hot patch failed", throwable);
            }
        });
    }

    /**
     * UcoreProxyActivity 调用：优先创建下载 APK 里的插件页面；没有补丁时创建宿主内置页面。
     */
    public static UcorePlugin createPluginForActivity(UcoreProxyActivity activity, Bundle savedInstanceState) throws Exception {
        if (activity == null) {
            return null;
        }
        PatchHandle patch = currentPatch;
        String entryClass = null;
        if (patch != null && patch.getUpdateInfo() != null && !TextUtils.isEmpty(patch.getUpdateInfo().getEntryClass())) {
            entryClass = patch.getUpdateInfo().getEntryClass();
        }
        if (TextUtils.isEmpty(entryClass)) {
            entryClass = getDefaultPluginEntryClass(activity);
        }

        Class<?> clazz = null;
        if (patch != null) {
            try {
                clazz = patch.getClassLoader().loadClass(entryClass);
            } catch (ClassNotFoundException ignored) {
                clazz = null;
            }
        }
        if (clazz == null) {
            clazz = activity.getClassLoader().loadClass(entryClass);
        }
        Object instance = clazz.getDeclaredConstructor().newInstance();
        if (!(instance instanceof UcorePlugin)) {
            throw new UcoreReloadException("入口类必须实现 UcorePlugin：" + entryClass);
        }
        UcorePlugin plugin = (UcorePlugin) instance;
        plugin.onCreate(activity, patch, savedInstanceState);
        return plugin;
    }

    /**
     * 让 Activity 在 onCreate 前尽量使用补丁 APK 的 Resources。
     * 这可以让补丁 MainActivity 里的 setContentView(R.layout.activity_main) 读取补丁 APK 的布局。
     */
    public static boolean applyPatchResourcesToActivity(Activity activity) {
        PatchHandle handle = currentPatch;
        if (activity == null || handle == null || handle.getResources() == null) {
            return false;
        }
        try {
            // 关键顺序不能反：必须先把 Activity 的 baseContext/resources/classLoader 切到补丁环境，
            // 再重建 Theme。否则 AppCompatActivity 会用宿主 Resources 创建 Theme，
            // 补丁 APK 里新增的 androidx/appcompat/aar 资源属性不会进入 Theme，最终报：
            // "You need to use a Theme.AppCompat theme"。
            Context base = getBaseContext(activity);
            if (base != null && !(base instanceof HotPatchContext)) {
                setBaseContext(activity, new HotPatchContext(base, handle));
                base = getBaseContext(activity);
            }

            replaceResourcesField(activity, handle.getResources());
            replaceResourcesField(base, handle.getResources());
            clearCachedInflater(activity);
            clearCachedInflater(base);
            rebuildPatchTheme(activity, handle);
            return true;
        } catch (Throwable throwable) {
            debug("THEME", "apply patch resources/theme failed: " + activity.getClass().getName(), throwable);
            return false;
        }
    }

    private static void rebuildPatchTheme(Activity activity, PatchHandle handle) {
        if (activity == null || handle == null) {
            return;
        }
        int themeResId = handle.getThemeResIdForActivity(activity.getClass().getName());
        if (themeResId == 0) {
            return;
        }
        try {
            // ContextThemeWrapper/Activity 会缓存 mTheme。旧 mTheme 一旦用宿主 Resources 建出来，
            // 后面再 setTheme 同一个 id 也不会彻底重建，所以这里必须清空缓存字段。
            clearThemeCache(activity);
            Context base = getBaseContext(activity);
            clearThemeCache(base);
            activity.setTheme(themeResId);
            Resources.Theme theme = activity.getTheme();
            if (theme != null) {
                try {
                    theme.applyStyle(themeResId, true);
                } catch (Throwable ignored) {
                }
            }
            debug("THEME", "patch theme applied: activity=" + activity.getClass().getName() + " theme=" + themeResId, null);
        } catch (Throwable throwable) {
            debug("THEME", "apply patch theme failed: " + activity.getClass().getName() + " theme=" + themeResId, throwable);
        }
    }


    /**
     * 把补丁 APK 里新增、但没有注册在宿主 Manifest 的 Activity 转发到 UcoreStubActivity。
     * 这样 MainActivity 里正常写 startActivity(new Intent(this, SecondActivity.class)) 也可以打开补丁 APK 里的 SecondActivity。
     */
    public static Intent wrapActivityIntentIfNeeded(Context context, Intent rawIntent) {
        if (context == null || rawIntent == null || currentPatch == null) {
            return rawIntent;
        }
        try {
            if (rawIntent.getStringExtra(EXTRA_TARGET_ACTIVITY) != null) {
                return rawIntent;
            }
            String targetClass = resolveIntentActivityClass(context, rawIntent);
            if (TextUtils.isEmpty(targetClass)) {
                return rawIntent;
            }
            if (isSdkOrSystemClass(context, targetClass)) {
                return rawIntent;
            }
            if (isActivityDeclaredInHost(context, targetClass)) {
                debug("ACTIVITY", "target already declared in host, no proxy: " + targetClass, null);
                return rawIntent;
            }
            if (!canLoadActivityFromPatch(targetClass)) {
                debug("ACTIVITY", "target not loadable from patch: " + targetClass, null);
                return rawIntent;
            }
            ComponentName proxyComponent = chooseProxyActivityComponent(context);
            if (proxyComponent == null) {
                debug("ACTIVITY", "no declared proxy activity found for target: " + targetClass, null);
                return rawIntent;
            }
            Intent proxy = new Intent(rawIntent);
            ComponentName original = rawIntent.getComponent();
            if (original != null) {
                proxy.putExtra(EXTRA_ORIGINAL_COMPONENT, original.flattenToString());
            }
            proxy.putExtra(EXTRA_TARGET_ACTIVITY, targetClass);
            proxy.setExtrasClassLoader(currentPatch.getClassLoader());
            proxy.setComponent(proxyComponent);
            debug("ACTIVITY", "proxy activity: target=" + targetClass + ", proxy=" + proxyComponent.flattenToString(), null);
            return proxy;
        } catch (Throwable throwable) {
            debug("ACTIVITY", "wrap activity intent failed", throwable);
            return rawIntent;
        }
    }

    /**
     * 第三方工具或 iApp 可直接调用：UcoreReload.startPatchActivity(this, "完整Activity类名")。
     * 它会自动走未注册 Activity 代理，不要求目标 Activity 写进宿主 Manifest。
     */
    public static boolean startPatchActivity(Context context, String activityClassName) {
        if (context == null || TextUtils.isEmpty(activityClassName)) {
            return false;
        }
        try {
            Intent intent = new Intent();
            intent.setComponent(new ComponentName(context.getPackageName(), activityClassName));
            Intent fixed = wrapActivityIntentIfNeeded(context, intent);
            if (!(context instanceof Activity)) {
                fixed.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            }
            context.startActivity(fixed);
            debug("ACTIVITY", "startPatchActivity ok: " + activityClassName, null);
            return true;
        } catch (Throwable throwable) {
            debug("ACTIVITY", "startPatchActivity failed: " + activityClassName, throwable);
            return false;
        }
    }

    private static String resolveIntentActivityClass(Context context, Intent intent) {
        if (intent == null) {
            return null;
        }
        ComponentName component = intent.getComponent();
        if (component != null) {
            return component.getClassName();
        }
        try {
            android.content.pm.ResolveInfo info = context.getPackageManager().resolveActivity(intent, 0);
            if (info != null && info.activityInfo != null) {
                return info.activityInfo.name;
            }
        } catch (Throwable ignored) {
        }
        return null;
    }

    private static ComponentName chooseProxyActivityComponent(Context context) {
        if (context == null) {
            return null;
        }
        String pkg = context.getPackageName();
        String stub = UcoreStubActivity.class.getName();
        if (isActivityDeclaredInHost(context, stub)) {
            return new ComponentName(pkg, stub);
        }
        String current = getActivityClassNameFromContext(context);
        if (!TextUtils.isEmpty(current) && isActivityDeclaredInHost(context, current)) {
            return new ComponentName(pkg, current);
        }
        try {
            PackageInfo info;
            if (Build.VERSION.SDK_INT >= 33) {
                info = context.getPackageManager().getPackageInfo(pkg, PackageManager.PackageInfoFlags.of(PackageManager.GET_ACTIVITIES));
            } else {
                info = context.getPackageManager().getPackageInfo(pkg, PackageManager.GET_ACTIVITIES);
            }
            if (info.activities != null) {
                for (ActivityInfo activity : info.activities) {
                    if (activity != null && !TextUtils.isEmpty(activity.name)
                            && !activity.name.startsWith("com.uaoan.ucorereload.sdk.")) {
                        return new ComponentName(pkg, activity.name);
                    }
                }
                for (ActivityInfo activity : info.activities) {
                    if (activity != null && !TextUtils.isEmpty(activity.name)) {
                        return new ComponentName(pkg, activity.name);
                    }
                }
            }
        } catch (Throwable throwable) {
            debug("ACTIVITY", "choose proxy activity failed", throwable);
        }
        return null;
    }

    private static String getActivityClassNameFromContext(Context context) {
        Context c = context;
        while (c != null) {
            if (c instanceof Activity) {
                return c.getClass().getName();
            }
            if (c instanceof ContextWrapper) {
                Context base = ((ContextWrapper) c).getBaseContext();
                if (base == c) break;
                c = base;
            } else {
                break;
            }
        }
        return null;
    }

    private static boolean isActivityDeclaredInHost(Context context, String className) {
        try {
            PackageInfo info;
            if (Build.VERSION.SDK_INT >= 33) {
                info = context.getPackageManager().getPackageInfo(
                        context.getPackageName(),
                        PackageManager.PackageInfoFlags.of(PackageManager.GET_ACTIVITIES)
                );
            } else {
                info = context.getPackageManager().getPackageInfo(context.getPackageName(), PackageManager.GET_ACTIVITIES);
            }
            ActivityInfo[] activities = info.activities;
            if (activities == null) {
                return false;
            }
            for (ActivityInfo activity : activities) {
                if (activity != null && className.equals(activity.name)) {
                    return true;
                }
            }
        } catch (Throwable ignored) {
        }
        return false;
    }

    static boolean shouldLoadActivityFromPatch(Context context, String className) {
        if (context == null || TextUtils.isEmpty(className)) {
            return false;
        }
        if (isSdkOrSystemClass(context, className)) {
            return false;
        }
        return canLoadActivityFromPatch(className);
    }

    private static boolean isSdkOrSystemClass(Context context, String className) {
        if (TextUtils.isEmpty(className)) {
            return true;
        }
        String pkg = context == null ? "" : context.getPackageName();
        return className.startsWith("android.")
                || className.startsWith("androidx.")
                || className.startsWith("java.")
                || className.startsWith("javax.")
                || className.startsWith("kotlin.")
                || className.startsWith("dalvik.")
                || className.startsWith("com.uaoan.ucorereload.sdk.")
                || (!TextUtils.isEmpty(pkg) && className.equals(pkg + ".UcoreApp"))
                || (!TextUtils.isEmpty(pkg) && className.equals(pkg + ".BuildConfig"));
    }

    private static boolean canLoadActivityFromPatch(String className) {
        PatchHandle handle = currentPatch;
        if (handle == null || handle.getClassLoader() == null || TextUtils.isEmpty(className)) {
            return false;
        }
        try {
            Class<?> clazz = handle.getClassLoader().loadClass(className);
            return Activity.class.isAssignableFrom(clazz);
        } catch (Throwable ignored) {
            return false;
        }
    }

    private static boolean preparePatchLaunchOrDisableIfCrashed(Context context, int code) {
        if (context == null || code <= 0) {
            return true;
        }
        SharedPreferences sp = prefs(context);
        int disabledCode = sp.getInt(KEY_DISABLED_PATCH_CODE, 0);
        if (disabledCode == code) {
            clearPatch(context);
            return false;
        }
        int launchingCode = sp.getInt(KEY_LAUNCHING_PATCH_CODE, 0);
        int okCode = sp.getInt(KEY_LAUNCH_OK_CODE, 0);
        if (launchingCode == code && okCode != code) {
            // 上一次启用该补丁后还没来得及标记启动成功就崩溃了，自动禁用，避免无限闪退。
            sp.edit().putInt(KEY_DISABLED_PATCH_CODE, code).commit();
            clearPatch(context);
            return false;
        }
        sp.edit().putInt(KEY_LAUNCHING_PATCH_CODE, code).commit();
        return true;
    }

    private static void markPatchLaunchOkLater(final Context context) {
        if (context == null || !isMainProcess(context)) {
            return;
        }
        final int code = getAppliedPatchCode(context);
        if (code <= 0) {
            return;
        }
        new Handler(Looper.getMainLooper()).postDelayed(new Runnable() {
            @Override
            public void run() {
                try {
                    prefs(context).edit()
                            .putInt(KEY_LAUNCH_OK_CODE, code)
                            .remove(KEY_LAUNCHING_PATCH_CODE)
                            .commit();
                } catch (Throwable ignored) {
                }
            }
        }, 6000L);
    }

    private static void forceLoadHostSdkClasses(Context context) {
        try {
            ClassLoader host = context.getClassLoader();
            String[] names = new String[]{
                    "com.uaoan.ucorereload.sdk.UcoreReload",
                    "com.uaoan.ucorereload.sdk.UcoreActivity",
                    "com.uaoan.ucorereload.sdk.HotPatchContext",
                    "com.uaoan.ucorereload.sdk.PatchHandle",
                    "com.uaoan.ucorereload.sdk.UpdateInfo",
                    "com.uaoan.ucorereload.sdk.UcoreReloadException"
            };
            for (String name : names) {
                try {
                    Class.forName(name, false, host);
                } catch (Throwable ignored) {
                }
            }
        } catch (Throwable ignored) {
        }
    }

    private static boolean mergeSavedPatchDexElements(Context context, File patchFile) {
        if (context == null || patchFile == null || !patchFile.exists()) {
            return false;
        }
        String path = patchFile.getAbsolutePath();
        if (path.equals(mergedPatchPath)) {
            return true;
        }
        synchronized (LOCK) {
            if (path.equals(mergedPatchPath)) {
                return true;
            }
            try {
                mergeDexElements(context, patchFile);
                mergedPatchPath = path;
                return true;
            } catch (Throwable throwable) {
                debug("DEX", "merge saved patch dex failed", throwable);
                return false;
            }
        }
    }

    public static boolean ensureActivityProxyInstalled(Context context) {
        return installInstrumentationHook(context);
    }

    private static boolean installInstrumentationHook(Context context) {
        if (instrumentationInstalled) {
            return true;
        }
        synchronized (LOCK) {
            if (instrumentationInstalled) {
                return true;
            }
            try {
                Class<?> activityThreadClass = Class.forName("android.app.ActivityThread");
                Method currentActivityThread = activityThreadClass.getDeclaredMethod("currentActivityThread");
                currentActivityThread.setAccessible(true);
                Object activityThread = currentActivityThread.invoke(null);
                if (activityThread == null) {
                    return false;
                }
                Field field = activityThreadClass.getDeclaredField("mInstrumentation");
                field.setAccessible(true);
                Instrumentation base = (Instrumentation) field.get(activityThread);
                if (base instanceof UcoreReloadInstrumentation) {
                    instrumentationInstalled = true;
                    return true;
                }
                Context appCtx = context.getApplicationContext();
                if (appCtx == null) appCtx = context;
                field.set(activityThread, new UcoreReloadInstrumentation(appCtx, base));
                instrumentationInstalled = true;
                debug("HOOK", "instrumentation hook installed", null);
                return true;
            } catch (Throwable throwable) {
                debug("HOOK", "install instrumentation hook failed", throwable);
                return false;
            }
        }
    }

    private static void registerActivityResourceHook(Application application) {
        if (Build.VERSION.SDK_INT >= 29) {
            application.registerActivityLifecycleCallbacks(new Application.ActivityLifecycleCallbacks() {
                @Override public void onActivityPreCreated(Activity activity, Bundle savedInstanceState) { applyPatchResourcesToActivity(activity); }
                @Override public void onActivityCreated(Activity activity, Bundle savedInstanceState) { applyPatchResourcesToActivity(activity); }
                @Override public void onActivityStarted(Activity activity) { }
                @Override public void onActivityResumed(Activity activity) { applyPatchResourcesToActivity(activity); }
                @Override public void onActivityPaused(Activity activity) { }
                @Override public void onActivityStopped(Activity activity) { }
                @Override public void onActivitySaveInstanceState(Activity activity, Bundle outState) { }
                @Override public void onActivityDestroyed(Activity activity) { }
            });
        } else {
            application.registerActivityLifecycleCallbacks(new Application.ActivityLifecycleCallbacks() {
                @Override public void onActivityCreated(Activity activity, Bundle savedInstanceState) { applyPatchResourcesToActivity(activity); }
                @Override public void onActivityStarted(Activity activity) { }
                @Override public void onActivityResumed(Activity activity) { applyPatchResourcesToActivity(activity); }
                @Override public void onActivityPaused(Activity activity) { }
                @Override public void onActivityStopped(Activity activity) { }
                @Override public void onActivitySaveInstanceState(Activity activity, Bundle outState) { }
                @Override public void onActivityDestroyed(Activity activity) { }
            });
        }
    }

    private static boolean isMainProcess(Context context) {
        try {
            int pid = android.os.Process.myPid();
            ActivityManager am = (ActivityManager) context.getSystemService(Context.ACTIVITY_SERVICE);
            if (am == null) {
                return true;
            }
            java.util.List<ActivityManager.RunningAppProcessInfo> processes = am.getRunningAppProcesses();
            if (processes == null) {
                return true;
            }
            for (ActivityManager.RunningAppProcessInfo process : processes) {
                if (process.pid == pid) {
                    return context.getPackageName().equals(process.processName);
                }
            }
        } catch (Throwable ignored) {
        }
        return true;
    }

    private static Context getBaseContext(Context context) {
        if (context instanceof ContextWrapper) {
            try {
                Field field = ContextWrapper.class.getDeclaredField("mBase");
                field.setAccessible(true);
                Object value = field.get(context);
                return value instanceof Context ? (Context) value : null;
            } catch (Throwable ignored) {
            }
        }
        return null;
    }


    private static boolean setBaseContext(ContextWrapper wrapper, Context base) {
        if (wrapper == null || base == null) {
            return false;
        }
        try {
            Field field = ContextWrapper.class.getDeclaredField("mBase");
            field.setAccessible(true);
            field.set(wrapper, base);
            return true;
        } catch (Throwable ignored) {
            return false;
        }
    }

    private static void replaceResourcesField(Object target, Resources resources) {
        if (target == null || resources == null) {
            return;
        }
        setFieldIfExists(target, "mResources", resources);
    }

    private static void clearThemeCache(Object target) {
        if (target == null) {
            return;
        }
        setFieldIfExists(target, "mTheme", null);
        setIntFieldIfExists(target, "mThemeResource", 0);
    }

    private static void clearCachedInflater(Object target) {
        if (target == null) {
            return;
        }
        setFieldIfExists(target, "mInflater", null);
    }

    private static boolean setIntFieldIfExists(Object target, String fieldName, int value) {
        if (target == null || TextUtils.isEmpty(fieldName)) {
            return false;
        }
        Class<?> clazz = target.getClass();
        while (clazz != null && clazz != Object.class) {
            try {
                Field field = clazz.getDeclaredField(fieldName);
                field.setAccessible(true);
                if (field.getType() == Integer.TYPE) {
                    field.setInt(target, value);
                    return true;
                }
                field.set(target, value);
                return true;
            } catch (NoSuchFieldException e) {
                clazz = clazz.getSuperclass();
            } catch (Throwable e) {
                return false;
            }
        }
        return false;
    }

    private static boolean setFieldIfExists(Object target, String fieldName, Object value) {
        if (target == null || TextUtils.isEmpty(fieldName)) {
            return false;
        }
        Class<?> clazz = target.getClass();
        while (clazz != null && clazz != Object.class) {
            try {
                Field field = clazz.getDeclaredField(fieldName);
                field.setAccessible(true);
                field.set(target, value);
                return true;
            } catch (NoSuchFieldException e) {
                clazz = clazz.getSuperclass();
            } catch (Throwable e) {
                return false;
            }
        }
        return false;
    }

    public static void restartApp(Context context) {
        Context appContext = getAppContext(context);
        Intent intent = appContext.getPackageManager().getLaunchIntentForPackage(appContext.getPackageName());
        if (intent != null) {
            intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK);
            appContext.startActivity(intent);
        }
        android.os.Process.killProcess(android.os.Process.myPid());
        System.exit(0);
    }

    private static PatchHandle loadPatch(Context context, File patchFile, UpdateInfo info, boolean save) throws Exception {
        if (patchFile == null || !patchFile.exists()) {
            throw new UcoreReloadException("补丁文件不存在");
        }
        if (Build.VERSION.SDK_INT >= 34) {
            patchFile.setWritable(false, false);
            patchFile.setReadOnly();
        }

        PackageInfo packageInfo = getArchivePackageInfo(context, patchFile);
        String archivePackage = packageInfo == null ? "" : packageInfo.packageName;
        String configPackage = info == null ? "" : info.getPackageName();

        // 资源查找必须使用补丁 APK 自己的资源包名。
        // 后台 packageName 如果填错成宿主包名，会导致 getIdentifier("hot_message") 找不到资源。
        // 所以这里优先采用 APK 文件里解析出来的真实包名；解析不到时才使用后台配置值。
        String patchPackage = !TextUtils.isEmpty(archivePackage) ? archivePackage : configPackage;
        if (TextUtils.isEmpty(patchPackage)) {
            patchPackage = context.getPackageName();
        }
        if (info != null && !patchPackage.equals(configPackage)) {
            info.setPackageName(patchPackage);
        }

        File optDir = new File(context.getCodeCacheDir(), "ucore_reload_opt");
        if (!optDir.exists()) {
            //noinspection ResultOfMethodCallIgnored
            optDir.mkdirs();
        }
        String nativeLibrarySearchPath = prepareNativeLibrarySearchPath(context, patchFile, info);

        DexClassLoader loader = new ChildFirstDexClassLoader(
                patchFile.getAbsolutePath(),
                optDir.getAbsolutePath(),
                nativeLibrarySearchPath,
                context.getClassLoader(),
                context.getPackageName() + ".",
                new String[]{
                        context.getPackageName() + ".UcoreApp",
                        context.getPackageName() + ".BuildConfig",
                        "com.uaoan.ucorereload.sdk."
                }
        );

        if (info == null) {
            info = new UpdateInfo();
        }
        // 新版默认使用独立 ChildFirstDexClassLoader + Proxy 容器加载下载 APK。
        // 不再自动合并 dexElements，避免下载 APK 里的 MainActivity 覆盖宿主代理入口。

        Resources resources = createResources(context, patchFile);
        int applicationThemeResId = resolveApplicationThemeResId(packageInfo);
        if (applicationThemeResId == 0) {
            applicationThemeResId = resolveFallbackAppCompatThemeResId(resources, patchPackage);
        }
        Map<String, Integer> activityThemeResIds = resolveActivityThemeResIds(packageInfo, patchPackage);
        PatchHandle handle = new PatchHandle(context, patchFile, loader, resources, patchPackage, info,
                applicationThemeResId, activityThemeResIds);
        debug("THEME", "patch theme app=" + applicationThemeResId + " activities=" + activityThemeResIds.size(), null);

        synchronized (LOCK) {
            currentPatch = handle;
            if (save) {
                prefs(context).edit()
                        .putString(KEY_PATCH_FILE, patchFile.getAbsolutePath())
                        .putString(KEY_PATCH_INFO, info.toJsonString())
                        .putInt(KEY_PATCH_CODE, info.getPatchCode())
                        .remove(KEY_DISABLED_PATCH_CODE)
                        .remove(KEY_LAUNCH_OK_CODE)
                        .remove(KEY_LAUNCHING_PATCH_CODE)
                        .commit();
            }
        }

        callEntryIfNeeded(handle, info);
        return handle;
    }

    private static void callEntryIfNeeded(PatchHandle handle, UpdateInfo info) throws Exception {
        if (info == null || TextUtils.isEmpty(info.getEntryClass())) {
            return;
        }
        Class<?> clazz;
        try {
            clazz = handle.getClassLoader().loadClass(info.getEntryClass());
        } catch (ClassNotFoundException e) {
            // entryClass 是可选能力。很多场景只需要热更新资源，或者直接上传完整 app APK 当补丁，
            // 这些 APK 里不一定包含后台填写的 PatchEntry。这里跳过入口类，不让资源热更新失败。
            return;
        } catch (NoClassDefFoundError e) {
            // 补丁入口类依赖缺失时也不阻断资源加载，避免“资源已可用但整体显示失败”。
            return;
        }
        Object instance = clazz.getDeclaredConstructor().newInstance();
        if (instance instanceof HotPatchEntry) {
            ((HotPatchEntry) instance).onLoad(handle.getPatchContext(), handle);
            return;
        }

        String methodName = TextUtils.isEmpty(info.getEntryMethod()) ? "onPatchLoaded" : info.getEntryMethod();
        Method method = findMethod(clazz, methodName, Context.class, PatchHandle.class);
        if (method != null) {
            method.setAccessible(true);
            method.invoke(instance, handle.getPatchContext(), handle);
            return;
        }
        method = findMethod(clazz, methodName, Context.class);
        if (method != null) {
            method.setAccessible(true);
            method.invoke(instance, handle.getPatchContext());
            return;
        }
        method = findMethod(clazz, methodName);
        if (method != null) {
            method.setAccessible(true);
            method.invoke(instance);
        }
    }

    private static Method findMethod(Class<?> clazz, String methodName, Class<?>... parameterTypes) {
        try {
            return clazz.getDeclaredMethod(methodName, parameterTypes);
        } catch (NoSuchMethodException e) {
            return null;
        }
    }

    public static boolean mergeDexElements(Context context, File patchFile) throws Exception {
        if (Build.VERSION.SDK_INT >= 34) {
            patchFile.setWritable(false, false);
            patchFile.setReadOnly();
        }
        ClassLoader hostLoader = context.getClassLoader();
        File optDir = new File(context.getCodeCacheDir(), "ucore_reload_opt");
        if (!optDir.exists()) {
            //noinspection ResultOfMethodCallIgnored
            optDir.mkdirs();
        }
        DexClassLoader patchLoader = new DexClassLoader(
                patchFile.getAbsolutePath(),
                optDir.getAbsolutePath(),
                null,
                hostLoader
        );

        Object hostPathList = getPathList(hostLoader);
        Object patchPathList = getPathList(patchLoader);
        Object hostElements = getDexElements(hostPathList);
        Object patchElements = getDexElements(patchPathList);
        Object merged = combineArray(patchElements, hostElements);
        setDexElements(hostPathList, merged);
        return true;
    }

    private static Object getPathList(ClassLoader loader) throws Exception {
        java.lang.reflect.Field field = BaseDexClassLoader.class.getDeclaredField("pathList");
        field.setAccessible(true);
        return field.get(loader);
    }

    private static Object getDexElements(Object pathList) throws Exception {
        java.lang.reflect.Field field = pathList.getClass().getDeclaredField("dexElements");
        field.setAccessible(true);
        return field.get(pathList);
    }

    private static void setDexElements(Object pathList, Object value) throws Exception {
        java.lang.reflect.Field field = pathList.getClass().getDeclaredField("dexElements");
        field.setAccessible(true);
        field.set(pathList, value);
    }

    private static Object combineArray(Object first, Object second) {
        Class<?> componentType = first.getClass().getComponentType();
        int firstLength = Array.getLength(first);
        int secondLength = Array.getLength(second);
        Object result = Array.newInstance(componentType, firstLength + secondLength);
        for (int i = 0; i < firstLength; i++) {
            Array.set(result, i, Array.get(first, i));
        }
        for (int i = 0; i < secondLength; i++) {
            Array.set(result, firstLength + i, Array.get(second, i));
        }
        return result;
    }


    private static String prepareNativeLibrarySearchPath(Context context, File patchFile, UpdateInfo info) throws Exception {
        File root = new File(context.getDir("ucore_reload", Context.MODE_PRIVATE), "native_libs");
        String key = "patch_" + (info == null ? 0 : info.getPatchCode()) + "_" + Math.abs(patchFile.getAbsolutePath().hashCode());
        File libRoot = new File(root, key);
        if (!libRoot.exists()) {
            //noinspection ResultOfMethodCallIgnored
            libRoot.mkdirs();
        }

        List<File> searchDirs = extractNativeLibraries(patchFile, libRoot);

        // 把宿主已安装的 native 目录也作为兜底。宿主没有相关 so 时不影响；宿主有时可避免重复下载包缺少 so 直接崩。
        addHostNativeLibraryDirs(context, searchDirs);

        String searchPath = joinNativeLibraryDirs(searchDirs);
        debug("SO", "native library searchPath=" + searchPath, null);
        return searchPath;
    }

    private static List<File> extractNativeLibraries(File patchFile, File libRoot) throws Exception {
        List<File> result = new ArrayList<>();
        if (patchFile == null || libRoot == null) {
            return result;
        }
        deleteChildren(libRoot);

        String[] compatibleAbis = getProcessCompatibleAbis();
        if (compatibleAbis == null || compatibleAbis.length == 0) {
            compatibleAbis = Build.SUPPORTED_ABIS;
        }
        if (compatibleAbis == null || compatibleAbis.length == 0) {
            return result;
        }

        Map<String, List<String>> copiedByAbi = new LinkedHashMap<>();
        Map<String, List<String>> allSoByAbi = new LinkedHashMap<>();

        ZipFile zipFile = new ZipFile(patchFile);
        try {
            // 先扫描 APK 里到底有哪些 ABI / so，便于 dumpDebugInfo 定位“补丁没打进 so”的问题。
            Enumeration<? extends ZipEntry> scanEntries = zipFile.entries();
            while (scanEntries.hasMoreElements()) {
                ZipEntry entry = scanEntries.nextElement();
                String name = entry == null ? null : entry.getName();
                if (entry == null || entry.isDirectory() || name == null || !name.startsWith("lib/") || !name.endsWith(".so")) {
                    continue;
                }
                String remain = name.substring("lib/".length());
                int slash = remain.indexOf('/');
                if (slash <= 0 || slash >= remain.length() - 1) {
                    continue;
                }
                String abi = remain.substring(0, slash);
                String soName = remain.substring(slash + 1);
                List<String> list = allSoByAbi.get(abi);
                if (list == null) {
                    list = new ArrayList<>();
                    allSoByAbi.put(abi, list);
                }
                list.add(soName);
            }

            boolean flatCopied = false;
            for (String abi : compatibleAbis) {
                if (TextUtils.isEmpty(abi)) {
                    continue;
                }
                String prefix = "lib/" + abi + "/";
                File abiDir = new File(libRoot, abi);
                boolean copied = false;
                List<String> copiedNames = new ArrayList<>();

                Enumeration<? extends ZipEntry> entries = zipFile.entries();
                while (entries.hasMoreElements()) {
                    ZipEntry entry = entries.nextElement();
                    String name = entry.getName();
                    if (entry.isDirectory() || name == null || !name.startsWith(prefix) || !name.endsWith(".so")) {
                        continue;
                    }
                    String soName = name.substring(prefix.length());
                    if (soName.contains("/")) {
                        continue;
                    }
                    if (!abiDir.exists()) {
                        //noinspection ResultOfMethodCallIgnored
                        abiDir.mkdirs();
                    }
                    File out = new File(abiDir, soName);
                    copyZipEntry(zipFile, entry, out);
                    copiedNames.add(soName);
                    copied = true;

                    // 兼容旧版本：第一组可用 ABI 也复制一份到根目录。
                    // DexClassLoader 的 librarySearchPath 使用根目录时仍能找到。
                    if (!flatCopied) {
                        copyZipEntry(zipFile, entry, new File(libRoot, soName));
                    }
                }

                if (copied) {
                    if (!result.contains(libRoot)) {
                        result.add(libRoot);
                    }
                    result.add(abiDir);
                    copiedByAbi.put(abi, copiedNames);
                    flatCopied = true;
                }
            }
        } finally {
            zipFile.close();
        }

        debug("SO", "process64=" + isCurrentProcess64Bit()
                + " compatibleAbis=" + Arrays.toString(compatibleAbis)
                + " apkSo=" + allSoByAbi
                + " copied=" + copiedByAbi
                + " root=" + libRoot.getAbsolutePath(), null);

        if (result.isEmpty() && !allSoByAbi.isEmpty()) {
            debug("SO", "APK contains native so, but none matches current process ABI. 64-bit process cannot load 32-bit .so; add matching arm64-v8a/armeabi-v7a dependency in patch.", null);
        }
        return result;
    }

    private static void copyZipEntry(ZipFile zipFile, ZipEntry entry, File out) throws Exception {
        File parent = out.getParentFile();
        if (parent != null && !parent.exists()) {
            //noinspection ResultOfMethodCallIgnored
            parent.mkdirs();
        }
        InputStream in = zipFile.getInputStream(entry);
        try {
            FileOutputStream fos = new FileOutputStream(out);
            try {
                byte[] buffer = new byte[8192];
                int len;
                while ((len = in.read(buffer)) != -1) {
                    fos.write(buffer, 0, len);
                }
            } finally {
                fos.close();
            }
        } finally {
            in.close();
        }
        //noinspection ResultOfMethodCallIgnored
        out.setReadOnly();
    }

    private static String[] getProcessCompatibleAbis() {
        try {
            if (Build.VERSION.SDK_INT >= 21) {
                boolean is64 = isCurrentProcess64Bit();
                String[] abis = is64 ? Build.SUPPORTED_64_BIT_ABIS : Build.SUPPORTED_32_BIT_ABIS;
                if (abis != null && abis.length > 0) {
                    return abis;
                }
                return Build.SUPPORTED_ABIS;
            }
        } catch (Throwable ignored) {
        }
        if (!TextUtils.isEmpty(Build.CPU_ABI2)) {
            return new String[]{Build.CPU_ABI, Build.CPU_ABI2};
        }
        return new String[]{Build.CPU_ABI};
    }

    private static boolean isCurrentProcess64Bit() {
        try {
            if (Build.VERSION.SDK_INT >= 23) {
                return android.os.Process.is64Bit();
            }
        } catch (Throwable ignored) {
        }
        String vm = System.getProperty("os.arch");
        return vm != null && vm.contains("64");
    }

    private static void addHostNativeLibraryDirs(Context context, List<File> dirs) {
        if (context == null || dirs == null) {
            return;
        }
        try {
            ApplicationInfo ai = context.getApplicationInfo();
            addDirIfValid(dirs, ai == null ? null : ai.nativeLibraryDir);
            if (Build.VERSION.SDK_INT >= 21 && ai != null) {
                try {
                    Field f = ApplicationInfo.class.getField("secondaryNativeLibraryDir");
                    Object v = f.get(ai);
                    if (v instanceof String) {
                        addDirIfValid(dirs, (String) v);
                    }
                } catch (Throwable ignored) {
                }
            }
        } catch (Throwable t) {
            debug("SO", "addHostNativeLibraryDirs failed", t);
        }
    }

    private static void addDirIfValid(List<File> dirs, String path) {
        if (dirs == null || TextUtils.isEmpty(path)) {
            return;
        }
        File dir = new File(path);
        if (!dir.exists() || !dir.isDirectory()) {
            return;
        }
        for (File item : dirs) {
            if (item != null && item.getAbsolutePath().equals(dir.getAbsolutePath())) {
                return;
            }
        }
        dirs.add(dir);
    }

    private static String joinNativeLibraryDirs(List<File> dirs) {
        if (dirs == null || dirs.isEmpty()) {
            return null;
        }
        StringBuilder sb = new StringBuilder();
        for (File dir : dirs) {
            if (dir == null) {
                continue;
            }
            if (sb.length() > 0) {
                sb.append(File.pathSeparator);
            }
            sb.append(dir.getAbsolutePath());
        }
        return sb.length() == 0 ? null : sb.toString();
    }

    private static void deleteChildren(File dir) {
        if (dir == null || !dir.exists() || !dir.isDirectory()) {
            return;
        }
        File[] files = dir.listFiles();
        if (files == null) {
            return;
        }
        for (File file : files) {
            if (file == null) {
                continue;
            }
            if (file.isDirectory()) {
                deleteChildren(file);
            }
            //noinspection ResultOfMethodCallIgnored
            file.delete();
        }
    }

    private static Resources createResources(Context context, File patchFile) throws Exception {
        AssetManager assetManager = AssetManager.class.getDeclaredConstructor().newInstance();
        Method addAssetPath = AssetManager.class.getDeclaredMethod("addAssetPath", String.class);
        addAssetPath.setAccessible(true);
        Object result = addAssetPath.invoke(assetManager, patchFile.getAbsolutePath());
        int cookie = result instanceof Integer ? (Integer) result : 0;
        if (cookie == 0) {
            throw new UcoreReloadException("补丁资源加载失败，请确认补丁是包含 resources.arsc 的 APK");
        }
        Resources host = context.getResources();
        return new Resources(assetManager, host.getDisplayMetrics(), host.getConfiguration());
    }

    private static String checkPatchAvailable(Context context, UpdateInfo info) throws Exception {
        if (info == null) {
            return "更新接口为空";
        }
        if (!info.isEnabled()) {
            return "后台未启用热更新";
        }
        if (info.getPatchCode() <= 0) {
            return "后台 patchCode 必须大于 0，否则会导致每次启动都重复下载";
        }
        if (TextUtils.isEmpty(info.getPatchUrl())) {
            return "后台没有配置补丁包地址";
        }
        if (!TextUtils.isEmpty(info.getTargetHostPackage()) && !info.getTargetHostPackage().equals(context.getPackageName())) {
            return "补丁包目标包名不匹配";
        }
        if (info.getMinHostVersionCode() > 0 && getHostVersionCode(context) < info.getMinHostVersionCode()) {
            return "宿主版本过低，需要先升级宿主 APK";
        }
        return null;
    }

    private static int getHostVersionCode(Context context) throws PackageManager.NameNotFoundException {
        PackageInfo info = context.getPackageManager().getPackageInfo(context.getPackageName(), 0);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            long code = info.getLongVersionCode();
            return code > Integer.MAX_VALUE ? Integer.MAX_VALUE : (int) code;
        }
        return info.versionCode;
    }

    private static int resolveApplicationThemeResId(PackageInfo packageInfo) {
        try {
            if (packageInfo != null && packageInfo.applicationInfo != null) {
                return packageInfo.applicationInfo.theme;
            }
        } catch (Throwable ignored) {
        }
        return 0;
    }

    private static int resolveFallbackAppCompatThemeResId(Resources resources, String patchPackage) {
        if (resources == null || TextUtils.isEmpty(patchPackage)) {
            return 0;
        }
        String[] names = new String[]{
                "AppTheme",
                "Theme.AppCompat.Light.NoActionBar",
                "Theme.AppCompat.Light",
                "Theme.AppCompat.NoActionBar",
                "Theme.AppCompat",
                "Theme.MaterialComponents.DayNight.NoActionBar",
                "Theme.MaterialComponents.Light.NoActionBar"
        };
        for (String name : names) {
            try {
                int id = resources.getIdentifier(name, "style", patchPackage);
                if (id != 0) {
                    debug("THEME", "fallback patch theme found: " + name + "=" + id, null);
                    return id;
                }
            } catch (Throwable ignored) {
            }
        }
        return 0;
    }

    private static Map<String, Integer> resolveActivityThemeResIds(PackageInfo packageInfo, String patchPackage) {
        Map<String, Integer> result = new HashMap<>();
        if (packageInfo == null || packageInfo.activities == null) {
            return result;
        }
        for (ActivityInfo activity : packageInfo.activities) {
            if (activity == null || TextUtils.isEmpty(activity.name) || activity.theme == 0) {
                continue;
            }
            String normalized = normalizeActivityClassName(activity.name, patchPackage);
            if (!TextUtils.isEmpty(normalized)) {
                result.put(normalized, activity.theme);
            }
            result.put(activity.name, activity.theme);
        }
        return result;
    }

    private static String normalizeActivityClassName(String name, String packageName) {
        if (TextUtils.isEmpty(name)) {
            return name;
        }
        if (name.startsWith(".")) {
            return TextUtils.isEmpty(packageName) ? name.substring(1) : packageName + name;
        }
        if (name.indexOf('.') < 0 && !TextUtils.isEmpty(packageName)) {
            return packageName + "." + name;
        }
        return name;
    }

    private static PackageInfo getArchivePackageInfo(Context context, File patchFile) {
        PackageManager pm = context.getPackageManager();
        int flags = PackageManager.GET_META_DATA | PackageManager.GET_ACTIVITIES;
        if (Build.VERSION.SDK_INT >= 33) {
            PackageInfo info = pm.getPackageArchiveInfo(
                    patchFile.getAbsolutePath(),
                    PackageManager.PackageInfoFlags.of(flags)
            );
            fixArchiveInfo(info, patchFile);
            return info;
        } else {
            PackageInfo info = pm.getPackageArchiveInfo(patchFile.getAbsolutePath(), flags);
            fixArchiveInfo(info, patchFile);
            return info;
        }
    }

    private static void fixArchiveInfo(PackageInfo info, File patchFile) {
        if (info != null && info.applicationInfo != null) {
            ApplicationInfo appInfo = info.applicationInfo;
            appInfo.sourceDir = patchFile.getAbsolutePath();
            appInfo.publicSourceDir = patchFile.getAbsolutePath();
            if (info.activities != null) {
                for (ActivityInfo activity : info.activities) {
                    if (activity != null && activity.applicationInfo != null) {
                        activity.applicationInfo.sourceDir = patchFile.getAbsolutePath();
                        activity.applicationInfo.publicSourceDir = patchFile.getAbsolutePath();
                    }
                }
            }
        }
    }

    private static File downloadPatch(Context context, URL url, UpdateInfo info, ListenerDispatcher dispatcher) throws Exception {
        File dir = new File(context.getDir("ucore_reload", Context.MODE_PRIVATE), "patches");
        if (!dir.exists()) {
            //noinspection ResultOfMethodCallIgnored
            dir.mkdirs();
        }
        String ext = getExtension(url.getPath());
        if (TextUtils.isEmpty(ext)) {
            ext = ".apk";
        }
        File temp = new File(dir, "downloading_" + System.currentTimeMillis() + ext);
        HttpURLConnection connection = (HttpURLConnection) url.openConnection();
        connection.setConnectTimeout(CONNECT_TIMEOUT);
        connection.setReadTimeout(READ_TIMEOUT);
        connection.setRequestProperty("Accept", "application/octet-stream,*/*");
        connection.connect();
        int code = connection.getResponseCode();
        if (code < 200 || code >= 300) {
            throw new UcoreReloadException("补丁下载失败，HTTP " + code);
        }
        long total = getContentLength(connection);
        long downloaded = 0;
        byte[] buffer = new byte[16 * 1024];
        try (InputStream input = new BufferedInputStream(connection.getInputStream());
             FileOutputStream fos = new FileOutputStream(temp);
             BufferedOutputStream output = new BufferedOutputStream(fos)) {
            int len;
            while ((len = input.read(buffer)) != -1) {
                output.write(buffer, 0, len);
                downloaded += len;
                int percent = total > 0 ? (int) Math.min(100, downloaded * 100 / total) : 0;
                dispatcher.onDownloadProgress(percent, downloaded, total);
            }
            output.flush();
            fos.getFD().sync();
        } finally {
            connection.disconnect();
        }
        if (!TextUtils.isEmpty(info.getSha256())) {
            String actual = sha256(temp);
            if (!actual.equalsIgnoreCase(info.getSha256())) {
                //noinspection ResultOfMethodCallIgnored
                temp.delete();
                throw new UcoreReloadException("补丁 SHA-256 校验失败，期望 " + info.getSha256() + "，实际 " + actual);
            }
        }
        File target = new File(dir, "patch_" + Math.max(info.getPatchCode(), (int) (System.currentTimeMillis() / 1000L)) + ext);
        if (target.exists()) {
            target.setWritable(true, false);
            //noinspection ResultOfMethodCallIgnored
            target.delete();
        }
        if (!temp.renameTo(target)) {
            copyFile(temp, target);
            //noinspection ResultOfMethodCallIgnored
            temp.delete();
        }
        target.setWritable(false, false);
        target.setReadOnly();
        return target;
    }

    private static URL buildUrl(String baseUrl, String target) throws Exception {
        URL base = new URL(baseUrl);
        return new URL(base, target);
    }

    private static String getText(String url) throws Exception {
        HttpURLConnection connection = (HttpURLConnection) new URL(url).openConnection();
        connection.setConnectTimeout(CONNECT_TIMEOUT);
        connection.setReadTimeout(READ_TIMEOUT);
        connection.setRequestProperty("Accept", "application/json");
        connection.connect();
        int code = connection.getResponseCode();
        if (code < 200 || code >= 300) {
            throw new UcoreReloadException("更新接口请求失败，HTTP " + code);
        }
        try (InputStream input = new BufferedInputStream(connection.getInputStream());
             ByteArrayOutputStream output = new ByteArrayOutputStream()) {
            byte[] buffer = new byte[4096];
            int len;
            while ((len = input.read(buffer)) != -1) {
                output.write(buffer, 0, len);
            }
            return output.toString("UTF-8");
        } finally {
            connection.disconnect();
        }
    }

    private static long getContentLength(HttpURLConnection connection) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
            return connection.getContentLengthLong();
        }
        return connection.getContentLength();
    }

    private static String getExtension(String path) {
        if (path == null) {
            return "";
        }
        int dot = path.lastIndexOf('.');
        if (dot < 0 || dot == path.length() - 1) {
            return "";
        }
        String ext = path.substring(dot).toLowerCase(Locale.US);
        if (".apk".equals(ext) || ".jar".equals(ext) || ".dex".equals(ext) || ".zip".equals(ext)) {
            return ext;
        }
        return ".apk";
    }

    private static String sha256(File file) throws Exception {
        MessageDigest digest = MessageDigest.getInstance("SHA-256");
        try (InputStream input = new FileInputStream(file)) {
            byte[] buffer = new byte[8192];
            int len;
            while ((len = input.read(buffer)) != -1) {
                digest.update(buffer, 0, len);
            }
        }
        byte[] bytes = digest.digest();
        StringBuilder sb = new StringBuilder(bytes.length * 2);
        for (byte b : bytes) {
            String hex = Integer.toHexString(b & 0xff);
            if (hex.length() == 1) {
                sb.append('0');
            }
            sb.append(hex);
        }
        return sb.toString();
    }

    private static void copyFile(File source, File target) throws Exception {
        try (InputStream input = new FileInputStream(source);
             FileOutputStream output = new FileOutputStream(target)) {
            byte[] buffer = new byte[8192];
            int len;
            while ((len = input.read(buffer)) != -1) {
                output.write(buffer, 0, len);
            }
            output.flush();
            output.getFD().sync();
        }
    }

    private static Context getAppContext(Context context) {
        Context appContext = context == null ? null : context.getApplicationContext();
        if (appContext == null) {
            appContext = context;
        }
        if (appContext == null) {
            throw new IllegalArgumentException("context == null");
        }
        return appContext;
    }

    private static SharedPreferences prefs(Context context) {
        return getAppContext(context).getSharedPreferences(PREF, Context.MODE_PRIVATE);
    }

    private static class ListenerDispatcher {
        private final UcoreReloadListener listener;
        private final Handler main = new Handler(Looper.getMainLooper());

        ListenerDispatcher(UcoreReloadListener listener) {
            this.listener = listener;
        }

        void onCheckStart() {
            post(new Runnable() {
                @Override
                public void run() {
                    if (listener != null) {
                        listener.onCheckStart();
                    }
                }
            });
        }

        void onPatchInfo(final UpdateInfo info) {
            post(new Runnable() {
                @Override
                public void run() {
                    if (listener != null) {
                        listener.onPatchInfo(info);
                    }
                }
            });
        }

        void onNoPatch(final String reason) {
            post(new Runnable() {
                @Override
                public void run() {
                    if (listener != null) {
                        listener.onNoPatch(reason);
                    }
                }
            });
        }

        void onDownloadProgress(final int percent, final long downloadedBytes, final long totalBytes) {
            post(new Runnable() {
                @Override
                public void run() {
                    if (listener != null) {
                        listener.onDownloadProgress(percent, downloadedBytes, totalBytes);
                    }
                }
            });
        }

        void onDownloaded(final File file) {
            post(new Runnable() {
                @Override
                public void run() {
                    if (listener != null) {
                        listener.onDownloaded(file);
                    }
                }
            });
        }

        void onPatchLoaded(final UpdateInfo info, final PatchHandle handle) {
            post(new Runnable() {
                @Override
                public void run() {
                    if (listener != null) {
                        listener.onPatchLoaded(info, handle);
                    }
                }
            });
        }

        void onNeedRestart(final UpdateInfo info) {
            post(new Runnable() {
                @Override
                public void run() {
                    if (listener != null) {
                        listener.onNeedRestart(info);
                    }
                }
            });
        }

        void onError(final Throwable throwable) {
            post(new Runnable() {
                @Override
                public void run() {
                    if (listener != null) {
                        listener.onError(throwable);
                    }
                }
            });
        }

        private void post(Runnable runnable) {
            if (Looper.myLooper() == Looper.getMainLooper()) {
                runnable.run();
            } else {
                main.post(runnable);
            }
        }
    }
}
