package com.uaoan.ucorereload;

import android.os.Bundle;
import android.view.View;
import android.widget.Button;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import com.uaoan.ucorereload.sdk.PatchHandle;
import com.uaoan.ucorereload.sdk.UcoreActivity;
import com.uaoan.ucorereload.sdk.UcoreReload;


public class MainActivity extends UcoreActivity {
    private TextView titleView;
    private TextView messageView;
    private ProgressBar progressBar;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);
        bindViews();
        renderState();
    }

    private void bindViews() {
        titleView = findViewById(R.id.titleView);
        messageView = findViewById(R.id.messageView);
        progressBar = findViewById(R.id.progressBar);
        Button checkButton = findViewById(R.id.checkButton);
        Button clearButton = findViewById(R.id.clearButton);

        if (checkButton != null) {
            checkButton.setText("自动热更新已开启");
            checkButton.setOnClickListener(new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    Toast.makeText(MainActivity.this, "进入 App 时 Application 已自动检查后台版本", Toast.LENGTH_SHORT).show();
                    renderState();
                }
            });
        }

        if (clearButton != null) {
            clearButton.setOnClickListener(new View.OnClickListener() {
                @Override
                public void onClick(View v) {
                    UcoreReload.clearPatch(MainActivity.this);
                    Toast.makeText(MainActivity.this, "已清除本地热更新 APK，正在重启", Toast.LENGTH_SHORT).show();
                    UcoreReload.restartApp(MainActivity.this);
                }
            });
        }
    }

    private void renderState() {
        PatchHandle patch = UcoreReload.getCurrentPatch();
        if (patch != null) {
            setTitleText(patch.getString("hot_title", "完整 APK 热更新已生效"));
            setMessageText(patch.getString(
                    "hot_message",
                    "当前页面按 AndroidManifest.xml 的启动 Activity 加载。\n" +
                            "Activity、Java 代码、res/layout、res/values、drawable 会优先使用下载 APK 中的同名内容。\n" +
                            "补丁包名：" + patch.getPackageName()
            ));
            if (progressBar != null) progressBar.setProgress(100);
        } else {
            setTitleText("UcoreReload 宿主 App");
            setMessageText("当前没有本地热更新 APK。进入 App 后 UcoreApp(Application) 会自动请求 PHP 后台，发现新 patchCode 后自动下载、保存并重启。重启后会优先加载下载 APK 里的 AndroidManifest 启动 Activity 同名类和资源。");
            if (progressBar != null) progressBar.setProgress(0);
        }
    }

    private void setTitleText(String text) {
        if (titleView != null) {
            titleView.setText(text);
        }
    }

    private void setMessageText(String text) {
        if (messageView != null) {
            messageView.setText(text);
        }
    }
}
