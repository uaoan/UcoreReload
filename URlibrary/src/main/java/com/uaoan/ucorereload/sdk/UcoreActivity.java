package com.uaoan.ucorereload.sdk;

import android.content.Context;
import android.content.Intent;
import android.content.res.AssetManager;
import android.content.res.Resources;
import android.os.Bundle;
import android.text.TextUtils;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;

import androidx.annotation.LayoutRes;
import androidx.annotation.Nullable;
import androidx.appcompat.app.AppCompatActivity;

/**
 * 宿主 Activity 基类。
 *
 * 作用：
 * 1. 保持 AndroidManifest.xml 里原本声明的启动 Activity 不变；
 * 2. 如果本地已经下载完整 APK 补丁，Activity 里的 setContentView(R.layout.xxx)、findViewById(R.id.xxx)
 *    会优先映射到补丁 APK 的 resources.arsc / res/layout / res/drawable / res/values；
 * 3. 如果同名 Activity 类已经由补丁 ClassLoader 创建，R.layout/R.id 常量也会来自补丁 APK。
 *
 * 你的 MainActivity 只需要继承 UcoreActivity，然后正常写 setContentView(R.layout.activity_main) 即可。
 */
public class UcoreActivity extends AppCompatActivity {
    private Resources.Theme patchTheme;
    private int patchThemeResId;

    @Override
    protected void attachBaseContext(Context newBase) {
        PatchHandle handle = UcoreReload.getCurrentPatch();
        if (handle != null) {
            super.attachBaseContext(new HotPatchContext(newBase, handle));
        } else {
            super.attachBaseContext(newBase);
        }
    }

    @Override
    protected void onCreate(@Nullable Bundle savedInstanceState) {
        UcoreReload.applyPatchResourcesToActivity(this);
        super.onCreate(savedInstanceState);
    }

    @Override
    public Resources getResources() {
        PatchHandle handle = UcoreReload.getCurrentPatch();
        if (handle != null && handle.getResources() != null) {
            return handle.getResources();
        }
        return super.getResources();
    }

    /** 获取宿主原始资源，供资源名映射使用。 */
    public Resources getHostResources() {
        return super.getResources();
    }

    @Override
    public AssetManager getAssets() {
        PatchHandle handle = UcoreReload.getCurrentPatch();
        if (handle != null && handle.getAssets() != null) {
            return handle.getAssets();
        }
        return super.getAssets();
    }

    @Override
    public ClassLoader getClassLoader() {
        PatchHandle handle = UcoreReload.getCurrentPatch();
        if (handle != null && handle.getClassLoader() != null) {
            return handle.getClassLoader();
        }
        return super.getClassLoader();
    }

    @Override
    public Resources.Theme getTheme() {
        PatchHandle handle = UcoreReload.getCurrentPatch();
        if (handle != null && handle.getResources() != null) {
            int themeResId = handle.getThemeResIdForActivity(getClass().getName());
            if (patchTheme == null || patchThemeResId != themeResId) {
                patchTheme = handle.getResources().newTheme();
                try {
                    Resources.Theme hostTheme = super.getTheme();
                    if (hostTheme != null) {
                        patchTheme.setTo(hostTheme);
                    }
                } catch (Throwable ignored) {
                }
                if (themeResId != 0) {
                    try {
                        patchTheme.applyStyle(themeResId, true);
                    } catch (Throwable ignored) {
                    }
                }
                patchThemeResId = themeResId;
            }
            return patchTheme;
        }
        return super.getTheme();
    }

    @Override
    public void setTheme(int resid) {
        super.setTheme(resid);
        patchTheme = null;
        patchThemeResId = 0;
    }

    @Override
    public LayoutInflater getLayoutInflater() {
        LayoutInflater inflater = super.getLayoutInflater();
        PatchHandle handle = UcoreReload.getCurrentPatch();
        if (handle != null) {
            return inflater.cloneInContext(new HotPatchContext(this, handle));
        }
        return inflater;
    }

    @Override
    public void setContentView(@LayoutRes int layoutResID) {
        View patchView = inflatePatchLayoutById(layoutResID, null, false);
        if (patchView != null) {
            super.setContentView(patchView);
            return;
        }
        super.setContentView(layoutResID);
    }

    @Override
    public void setContentView(View view) {
        super.setContentView(view);
    }

    @Override
    public void setContentView(View view, ViewGroup.LayoutParams params) {
        super.setContentView(view, params);
    }

    @Override
    public <T extends View> T findViewById(int id) {
        T view = super.findViewById(id);
        if (view != null) {
            return view;
        }
        PatchHandle handle = UcoreReload.getCurrentPatch();
        if (handle == null) {
            return null;
        }
        int patchId = mapHostIdToPatchId(id, "id");
        if (patchId != 0 && patchId != id) {
            return super.findViewById(patchId);
        }
        return null;
    }

    /** 按资源名加载补丁 layout；适合手动指定 activity_main 这种固定名称。 */
    public View setContentViewByName(String layoutName) {
        PatchHandle handle = UcoreReload.getCurrentPatch();
        if (handle != null && !TextUtils.isEmpty(layoutName)) {
            View view = handle.inflateLayout(this, layoutName, null, false);
            if (view != null) {
                super.setContentView(view);
                return view;
            }
        }
        return null;
    }


    @Override
    public void startActivity(Intent intent) {
        UcoreReload.ensureActivityProxyInstalled(this);
        super.startActivity(UcoreReload.wrapActivityIntentIfNeeded(this, intent));
    }

    @Override
    public void startActivity(Intent intent, @Nullable Bundle options) {
        UcoreReload.ensureActivityProxyInstalled(this);
        super.startActivity(UcoreReload.wrapActivityIntentIfNeeded(this, intent), options);
    }

    @Override
    public void startActivityForResult(Intent intent, int requestCode) {
        UcoreReload.ensureActivityProxyInstalled(this);
        super.startActivityForResult(UcoreReload.wrapActivityIntentIfNeeded(this, intent), requestCode);
    }

    @Override
    public void startActivityForResult(Intent intent, int requestCode, @Nullable Bundle options) {
        UcoreReload.ensureActivityProxyInstalled(this);
        super.startActivityForResult(UcoreReload.wrapActivityIntentIfNeeded(this, intent), requestCode, options);
    }

    @Override
    public void startActivities(Intent[] intents) {
        UcoreReload.ensureActivityProxyInstalled(this);
        super.startActivities(wrapIntents(intents));
    }

    @Override
    public void startActivities(Intent[] intents, @Nullable Bundle options) {
        UcoreReload.ensureActivityProxyInstalled(this);
        super.startActivities(wrapIntents(intents), options);
    }

    private Intent[] wrapIntents(Intent[] intents) {
        if (intents == null) {
            return null;
        }
        Intent[] result = new Intent[intents.length];
        for (int i = 0; i < intents.length; i++) {
            result[i] = UcoreReload.wrapActivityIntentIfNeeded(this, intents[i]);
        }
        return result;
    }

    private View inflatePatchLayoutById(int layoutResID, ViewGroup parent, boolean attachToRoot) {
        PatchHandle handle = UcoreReload.getCurrentPatch();
        if (handle == null || handle.getResources() == null) {
            return null;
        }
        int patchLayoutId = resolvePatchLayoutId(layoutResID);
        if (patchLayoutId == 0) {
            return null;
        }
        try {
            LayoutInflater inflater = super.getLayoutInflater().cloneInContext(new HotPatchContext(this, handle));
            return inflater.inflate(patchLayoutId, parent, attachToRoot);
        } catch (Throwable throwable) {
            return null;
        }
    }

    private int resolvePatchLayoutId(int layoutResID) {
        PatchHandle handle = UcoreReload.getCurrentPatch();
        if (handle == null || handle.getResources() == null) {
            return 0;
        }
        // 1. 如果当前 Activity 类来自补丁 APK，R.layout.xxx 本身就是补丁资源 ID。
        try {
            String type = handle.getResources().getResourceTypeName(layoutResID);
            if ("layout".equals(type)) {
                return layoutResID;
            }
        } catch (Throwable ignored) {
        }
        // 2. 如果当前 Activity 类来自宿主 APK，R.layout.xxx 是宿主资源 ID，按资源名映射到补丁 APK。
        return mapHostIdToPatchId(layoutResID, "layout");
    }

    private int mapHostIdToPatchId(int hostId, String expectedType) {
        PatchHandle handle = UcoreReload.getCurrentPatch();
        if (handle == null) {
            return 0;
        }
        try {
            Resources host = super.getResources();
            String type = host.getResourceTypeName(hostId);
            if (!TextUtils.isEmpty(expectedType) && !expectedType.equals(type)) {
                return 0;
            }
            String name = host.getResourceEntryName(hostId);
            return handle.getIdentifier(name, type);
        } catch (Throwable ignored) {
            return 0;
        }
    }
}
