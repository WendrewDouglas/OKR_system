# Regras ProGuard/R8 para o OKR app (release com minify).
# O plugin Flutter já traz regras para o engine; aqui reforçamos o que costuma
# quebrar quando o minify está ligado (Firebase, notificações, reflection).

# ---- Flutter ----
-keep class io.flutter.** { *; }
-keep class io.flutter.plugins.** { *; }
-keep class io.flutter.embedding.** { *; }
-dontwarn io.flutter.**

# ---- Firebase (Core / Messaging) ----
-keep class com.google.firebase.** { *; }
-keep class com.google.android.gms.** { *; }
-dontwarn com.google.firebase.**
-dontwarn com.google.android.gms.**

# ---- flutter_local_notifications ----
-keep class com.dexterous.** { *; }
-dontwarn com.dexterous.**

# ---- image_picker ----
-keep class androidx.core.content.FileProvider { *; }

# ---- Modelos anotados (json_serializable é codegen, mas mantemos anotações) ----
-keepattributes *Annotation*, Signature, InnerClasses, EnclosingMethod

# ---- Evita remover construtores usados por serialização/reflection dos plugins ----
-keepclassmembers class * {
    @androidx.annotation.Keep *;
}
-keep @androidx.annotation.Keep class * { *; }

# Desugaring
-dontwarn java.lang.invoke.**
