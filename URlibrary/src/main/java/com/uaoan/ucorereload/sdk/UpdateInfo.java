package com.uaoan.ucorereload.sdk;

import org.json.JSONException;
import org.json.JSONObject;

public class UpdateInfo {
    private boolean enabled;
    private int patchCode;
    private String patchName;
    private String patchUrl;
    private String sha256;
    private String packageName;
    private String targetHostPackage;
    private int minHostVersionCode;
    private String entryClass;
    private String entryMethod;
    private boolean mergeDex;
    private boolean restartAfterApply;
    private boolean autoApply;
    private String message;
    private String rawJson;

    public static UpdateInfo fromJson(String json) throws JSONException {
        JSONObject object = new JSONObject(json);
        UpdateInfo info = new UpdateInfo();
        info.rawJson = json;
        info.enabled = object.optBoolean("enabled", false);
        info.patchCode = object.optInt("patchCode", object.optInt("versionCode", 0));
        info.patchName = object.optString("patchName", object.optString("versionName", ""));
        info.patchUrl = object.optString("patchUrl", object.optString("apkUrl", ""));
        info.sha256 = object.optString("sha256", "");
        info.packageName = object.optString("packageName", "");
        info.targetHostPackage = object.optString("targetHostPackage", "");
        info.minHostVersionCode = object.optInt("minHostVersionCode", 0);
        info.entryClass = object.optString("entryClass", "");
        info.entryMethod = object.optString("entryMethod", "onPatchLoaded");
        info.mergeDex = object.optBoolean("mergeDex", false);
        info.restartAfterApply = object.optBoolean("restartAfterApply", false);
        info.autoApply = object.optBoolean("autoApply", true);
        info.message = object.optString("message", "");
        return info;
    }

    public JSONObject toJsonObject() throws JSONException {
        JSONObject object = new JSONObject();
        object.put("enabled", enabled);
        object.put("patchCode", patchCode);
        object.put("patchName", patchName == null ? "" : patchName);
        object.put("patchUrl", patchUrl == null ? "" : patchUrl);
        object.put("sha256", sha256 == null ? "" : sha256);
        object.put("packageName", packageName == null ? "" : packageName);
        object.put("targetHostPackage", targetHostPackage == null ? "" : targetHostPackage);
        object.put("minHostVersionCode", minHostVersionCode);
        object.put("entryClass", entryClass == null ? "" : entryClass);
        object.put("entryMethod", entryMethod == null ? "onPatchLoaded" : entryMethod);
        object.put("mergeDex", mergeDex);
        object.put("restartAfterApply", restartAfterApply);
        object.put("autoApply", autoApply);
        object.put("message", message == null ? "" : message);
        return object;
    }

    public String toJsonString() {
        try {
            return toJsonObject().toString();
        } catch (JSONException e) {
            return rawJson == null ? "{}" : rawJson;
        }
    }

    public boolean isEnabled() {
        return enabled;
    }

    public int getPatchCode() {
        return patchCode;
    }

    public String getPatchName() {
        return patchName;
    }

    public String getPatchUrl() {
        return patchUrl;
    }

    public String getSha256() {
        return sha256;
    }

    public String getPackageName() {
        return packageName;
    }

    public void setPackageName(String packageName) {
        this.packageName = packageName;
    }

    public String getTargetHostPackage() {
        return targetHostPackage;
    }

    public int getMinHostVersionCode() {
        return minHostVersionCode;
    }

    public String getEntryClass() {
        return entryClass;
    }

    public String getEntryMethod() {
        return entryMethod;
    }

    public boolean isMergeDex() {
        return mergeDex;
    }

    public boolean isRestartAfterApply() {
        return restartAfterApply;
    }

    public boolean isAutoApply() {
        return autoApply;
    }

    public String getMessage() {
        return message;
    }

    public String getRawJson() {
        return rawJson;
    }
}
