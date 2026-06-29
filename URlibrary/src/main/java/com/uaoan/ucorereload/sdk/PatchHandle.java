package com.uaoan.ucorereload.sdk;

import android.content.Context;
import android.content.res.AssetManager;
import android.content.res.Resources;
import android.graphics.drawable.Drawable;
import android.os.Build;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;

import java.io.File;
import java.io.IOException;
import java.io.InputStream;
import java.util.Collections;
import java.util.HashMap;
import java.util.Map;

public class PatchHandle {
    private final Context hostContext;
    private final File patchFile;
    private final ClassLoader classLoader;
    private final Resources resources;
    private final String packageName;
    private final UpdateInfo updateInfo;
    private final int applicationThemeResId;
    private final Map<String, Integer> activityThemeResIds;
    private final HotPatchContext patchContext;

    PatchHandle(Context hostContext,
                File patchFile,
                ClassLoader classLoader,
                Resources resources,
                String packageName,
                UpdateInfo updateInfo,
                int applicationThemeResId,
                Map<String, Integer> activityThemeResIds) {
        this.hostContext = hostContext.getApplicationContext();
        this.patchFile = patchFile;
        this.classLoader = classLoader;
        this.resources = resources;
        this.packageName = packageName;
        this.updateInfo = updateInfo;
        this.applicationThemeResId = applicationThemeResId;
        if (activityThemeResIds == null || activityThemeResIds.isEmpty()) {
            this.activityThemeResIds = Collections.emptyMap();
        } else {
            this.activityThemeResIds = Collections.unmodifiableMap(new HashMap<>(activityThemeResIds));
        }
        this.patchContext = new HotPatchContext(hostContext, this);
    }

    public Context getHostContext() {
        return hostContext;
    }

    public Context getPatchContext() {
        return patchContext;
    }

    public File getPatchFile() {
        return patchFile;
    }

    public ClassLoader getClassLoader() {
        return classLoader;
    }

    public Resources getResources() {
        return resources;
    }

    public AssetManager getAssets() {
        return resources == null ? null : resources.getAssets();
    }

    public String getPackageName() {
        return packageName;
    }

    public UpdateInfo getUpdateInfo() {
        return updateInfo;
    }


    /** 补丁 APK AndroidManifest.xml 里 application 的 theme。 */
    public int getApplicationThemeResId() {
        return applicationThemeResId;
    }

    /**
     * 获取补丁 Activity 的 theme。优先使用补丁 Manifest 中对应 Activity 的 theme，
     * 没有声明时回退到补丁 application theme。
     */
    public int getThemeResIdForActivity(String activityClassName) {
        if (activityClassName != null && activityClassName.length() > 0) {
            Integer id = activityThemeResIds.get(activityClassName);
            if (id != null && id != 0) {
                return id;
            }
        }
        return applicationThemeResId;
    }

    public Class<?> loadClass(String className) throws ClassNotFoundException {
        return classLoader.loadClass(className);
    }

    public Object newInstance(String className) throws Exception {
        Class<?> clazz = loadClass(className);
        return clazz.getDeclaredConstructor().newInstance();
    }

    public int getIdentifier(String name, String defType) {
        if (resources == null || name == null || name.length() == 0) {
            return 0;
        }
        int id = 0;

        // 1. 先按补丁 APK 的真实包名查找。资源热更新最常用的是这个。
        if (packageName != null && packageName.length() > 0) {
            id = resources.getIdentifier(name, defType, packageName);
            if (id != 0) {
                return id;
            }
        }

        // 2. 如果后台 packageName 或补丁包名填成了宿主包名，也尝试宿主包名。
        String hostPackage = hostContext.getPackageName();
        if (hostPackage != null && hostPackage.length() > 0 && !hostPackage.equals(packageName)) {
            id = resources.getIdentifier(name, defType, hostPackage);
            if (id != 0) {
                return id;
            }
        }

        // 3. 兼容传入完整资源名，例如 com.xxx.patch:string/hot_message。
        id = resources.getIdentifier(name, defType, null);
        return id;
    }

    public String getString(String name) {
        int id = getIdentifier(name, "string");
        if (id == 0) {
            return null;
        }
        return resources.getString(id);
    }

    public String getString(String name, String fallback) {
        String value = getString(name);
        return value == null ? fallback : value;
    }

    public int getColor(String name, int fallbackColor) {
        int id = getIdentifier(name, "color");
        if (id == 0) {
            return fallbackColor;
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            return resources.getColor(id, patchContext.getTheme());
        }
        return resources.getColor(id);
    }

    public Drawable getDrawable(String name) {
        int id = getIdentifier(name, "drawable");
        if (id == 0) {
            id = getIdentifier(name, "mipmap");
        }
        if (id == 0) {
            return null;
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            return resources.getDrawable(id, patchContext.getTheme());
        }
        return resources.getDrawable(id);
    }

    public int getId(String idName) {
        return getIdentifier(idName, "id");
    }

    public View findViewByName(View root, String idName) {
        if (root == null) {
            return null;
        }
        int id = getId(idName);
        return id == 0 ? null : root.findViewById(id);
    }

    public View inflateLayout(String layoutName, ViewGroup parent, boolean attachToRoot) {
        return inflateLayout(hostContext, layoutName, parent, attachToRoot);
    }

    public View inflateLayout(Context inflaterContext, String layoutName, ViewGroup parent, boolean attachToRoot) {
        int id = getIdentifier(layoutName, "layout");
        if (id == 0) {
            return null;
        }
        Context base = inflaterContext == null ? hostContext : inflaterContext;
        LayoutInflater inflater = LayoutInflater.from(base).cloneInContext(new HotPatchContext(base, this));
        return inflater.inflate(id, parent, attachToRoot);
    }

    public InputStream openAsset(String path) throws IOException {
        if (resources == null) {
            return null;
        }
        return resources.getAssets().open(path);
    }
}
