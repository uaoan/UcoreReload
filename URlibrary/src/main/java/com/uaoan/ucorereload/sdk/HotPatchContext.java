package com.uaoan.ucorereload.sdk;

import android.content.Context;
import android.content.ContextWrapper;
import android.content.res.AssetManager;
import android.content.res.Resources;

public class HotPatchContext extends ContextWrapper {
    private final PatchHandle handle;
    private Resources.Theme theme;

    HotPatchContext(Context base, PatchHandle handle) {
        super(base);
        this.handle = handle;
    }

    @Override
    public AssetManager getAssets() {
        AssetManager assets = handle.getAssets();
        return assets == null ? super.getAssets() : assets;
    }

    @Override
    public Resources getResources() {
        Resources resources = handle.getResources();
        return resources == null ? super.getResources() : resources;
    }

    @Override
    public Resources.Theme getTheme() {
        if (theme == null) {
            theme = getResources().newTheme();
            Resources.Theme baseTheme = super.getTheme();
            if (baseTheme != null) {
                try {
                    theme.setTo(baseTheme);
                } catch (Throwable ignored) {
                }
            }
            int themeResId = handle.getApplicationThemeResId();
            if (themeResId != 0) {
                try {
                    theme.applyStyle(themeResId, true);
                } catch (Throwable ignored) {
                }
            }
        }
        return theme;
    }

    @Override
    public ClassLoader getClassLoader() {
        ClassLoader loader = handle.getClassLoader();
        return loader == null ? super.getClassLoader() : loader;
    }

    @Override
    public String getPackageName() {
        String packageName = handle.getPackageName();
        return packageName == null || packageName.length() == 0 ? super.getPackageName() : packageName;
    }
}
