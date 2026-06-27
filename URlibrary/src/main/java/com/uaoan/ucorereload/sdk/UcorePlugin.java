package com.uaoan.ucorereload.sdk;

import android.content.Intent;
import android.os.Bundle;

/**
 * 非安装式整包热更新的真正页面入口。
 *
 * 不能让 Android 系统直接运行“未安装 APK”里的 Activity；所以宿主保留一个固定的 ProxyActivity，
 * 再把下载 APK 里的业务页面类加载进来运行。你要热更新 Java 代码时，修改实现这个接口的类即可。
 */
public interface UcorePlugin {
    void onCreate(UcoreProxyActivity activity, PatchHandle patch, Bundle savedInstanceState);

    void onStart();

    void onResume();

    void onPause();

    void onStop();

    void onDestroy();

    void onActivityResult(int requestCode, int resultCode, Intent data);

    void onSaveInstanceState(Bundle outState);

    boolean onBackPressed();
}
