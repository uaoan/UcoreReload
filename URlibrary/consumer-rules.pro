-keep class com.uaoan.ucorereload.sdk.** { *; }
-keep interface com.uaoan.ucorereload.sdk.** { *; }
# 如果补丁入口类被混淆，请在补丁工程里 keep 你的 entryClass，例如：
# -keep class com.yourcompany.patch.PatchEntry { *; }
