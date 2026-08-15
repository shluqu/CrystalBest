<?php
// 全局中间件定义文件
return [
    // 全局请求缓存
    // \think\middleware\CheckRequestCache::class,
    // 多语言加载
    // \think\middleware\LoadLangPack::class,

    // think-captcha 需要 ThinkPHP Session 保存图形验证码状态。
    // 本项目自己的登录态仍然使用 cex_user_sessions + cex_session Cookie，
    // 两者用途不同，不会替代账户登录会话。
    \think\middleware\SessionInit::class,
];
