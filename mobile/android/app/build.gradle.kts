import java.io.FileInputStream
import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Module 41 (CF-548): release signing with the UPLOAD key, read from android/key.properties (git-ignored) or, on a
// build server, from the FOTG_UPLOAD_* environment variables (the CI secret store). The key and its passwords are never
// committed. A PRODUCTION release cannot be built without it (the build stops below); local and staging release builds
// may fall back to the debug key for internal testing only. Play App Signing holds the app signing key itself.
val keystoreProperties = Properties().apply {
    val f = rootProject.file("key.properties")
    if (f.exists()) FileInputStream(f).use { load(it) }
}
fun signingValue(key: String, env: String): String? = (keystoreProperties.getProperty(key) ?: System.getenv(env))?.takeIf { it.isNotBlank() }
val uploadStoreFile = signingValue("storeFile", "FOTG_UPLOAD_STORE_FILE")
val uploadStorePassword = signingValue("storePassword", "FOTG_UPLOAD_STORE_PASSWORD")
val uploadKeyAlias = signingValue("keyAlias", "FOTG_UPLOAD_KEY_ALIAS")
val uploadKeyPassword = signingValue("keyPassword", "FOTG_UPLOAD_KEY_PASSWORD")
val hasUploadKey = listOf(uploadStoreFile, uploadStorePassword, uploadKeyAlias, uploadKeyPassword).all { it != null }

gradle.taskGraph.whenReady {
    if (!hasUploadKey && allTasks.any { it.name.contains("ProductionRelease") }) {
        throw GradleException("Release signing: a production release needs the upload key (android/key.properties or FOTG_UPLOAD_STORE_FILE / FOTG_UPLOAD_STORE_PASSWORD / FOTG_UPLOAD_KEY_ALIAS / FOTG_UPLOAD_KEY_PASSWORD). It is never signed with the debug key.")
    }
}

android {
    buildFeatures {
        resValues = true
    }
    namespace = "com.foodonthego.app"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        applicationId = "com.foodonthego.app"
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    // Environment flavors. Only the `local` flavor may talk to a plain-HTTP developer PC
    // (see src/local/AndroidManifest.xml); staging/production are HTTPS-only.
    flavorDimensions += "env"
    productFlavors {
        create("local") {
            dimension = "env"
            applicationIdSuffix = ".local"
            versionNameSuffix = "-local"
            resValue("string", "app_name", "FoodOnTheGo LOCAL")
        }
        create("staging") {
            dimension = "env"
            applicationIdSuffix = ".staging"
            versionNameSuffix = "-staging"
            resValue("string", "app_name", "FoodOnTheGo Staging")
        }
        create("production") {
            dimension = "env"
            resValue("string", "app_name", "FoodOnTheGo")
        }
    }

    signingConfigs {
        if (hasUploadKey) {
            create("upload") {
                storeFile = rootProject.file(uploadStoreFile!!)
                storePassword = uploadStorePassword
                keyAlias = uploadKeyAlias
                keyPassword = uploadKeyPassword
            }
        }
    }

    buildTypes {
        release {
            // The upload key when it is configured; otherwise the debug key for local / staging review builds only —
            // a production release without the upload key stops (see gradle.taskGraph above).
            signingConfig = if (hasUploadKey) signingConfigs.getByName("upload") else signingConfigs.getByName("debug")
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
