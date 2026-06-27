package com.uaoan.ucorereload;

import android.app.Application;

import com.uaoan.ucorereload.sdk.UcoreReload;

public class UcoreApp extends Application {
    /**
     * 后台创建 App 后复制这里的 AppID。
     * 热更新域名以及官网域名是 ： http://ucore.uaoan.cn
     */
    public static final String UCORE_APP_ID = "请填写后台创建App后生成的AppID";

    @Override
    protected void attachBaseContext(android.content.Context base) {
        super.attachBaseContext(base);

        UcoreReload.installSavedPatchEarly(base);
    }

    @Override
    public void onCreate() {
        super.onCreate();
        // 全部自动热更新流程入口：自动请求后台、自动下载、自动保存、自动重启
        UcoreReload.installInApplication(this, UCORE_APP_ID);
    }
}
