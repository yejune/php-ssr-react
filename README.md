# php-ssr-react

PHP Server-Side Rendering for React using QuickJS FFI.

Node.js 없이 PHP에서 React SSR을 실행합니다.

## 요구사항

- PHP 8.1+
- FFI 확장 활성화
- macOS (arm64, x64) 또는 Linux (x64, arm64)

## 설치

```bash
composer require yejune/php-ssr-react
```

설치 시 자동으로 QuickJS 바이너리가 다운로드됩니다.

### 수동 빌드 (선택)

pre-built 바이너리가 없는 플랫폼은 직접 빌드:

```bash
composer run build-quickjs
```

## 사용법

```php
<?php
require 'vendor/autoload.php';

use PhpSsrReact\QuickJS;
use PhpSsrReact\Engine;

// QuickJS 인스턴스 생성
$js = new QuickJS();

// JavaScript 실행
$result = $js->eval('1 + 2');
echo $result; // 3

// React SSR
$engine = new Engine($js);
$html = $engine->render('App', ['title' => 'Hello']);
```

## 아키텍처

```
┌─────────────────────────────────────────┐
│              PHP Application            │
├─────────────────────────────────────────┤
│           PhpSsrReact\Engine            │
├─────────────────────────────────────────┤
│           PhpSsrReact\QuickJS           │
├─────────────────────────────────────────┤
│              PHP FFI                    │
├─────────────────────────────────────────┤
│         libquickjs.dylib/.so            │
└─────────────────────────────────────────┘
```

## 지원 플랫폼

| 플랫폼 | 아키텍처 |
|--------|----------|
| macOS | arm64 (Apple Silicon) |
| macOS | x64 (Intel) |
| Linux | x64 |
| Linux | arm64 |

## 라이선스

MIT License

### QuickJS

이 패키지는 [QuickJS](https://bellard.org/quickjs/)를 사용합니다.

QuickJS는 Fabrice Bellard와 Charlie Gordon이 개발한 JavaScript 엔진으로, MIT 라이선스로 배포됩니다.
