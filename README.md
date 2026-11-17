# HUIT Schedule Hub

Web app PHP/MySQL giúp phòng đào tạo phân lịch giảng dạy đa cơ sở và giảng viên tra cứu lịch cá nhân theo tuần hoặc tháng.

## Tính năng

- Đăng nhập và phân quyền quản trị viên / giảng viên.
- Tạo, sửa, xóa lịch dạy; lọc lịch theo cơ sở và giảng viên.
- Gợi ý giảng viên theo từ khóa chuyên môn, lịch trống, số lần chuyển cơ sở và khoảng trống trong ngày.
- Chặn lịch trùng giảng viên, trùng phòng và lịch chuyển cơ sở không đủ thời gian.
- Cổng giảng viên hiển thị lịch tuần/tháng cùng cơ sở, địa chỉ và phòng học.
- Biểu đồ phân bổ số lớp theo ngày trong tuần.

## Yêu cầu

- PHP 8.2 trở lên, Composer và MySQL 8.0 trở lên.
- PHP extensions `pdo_mysql` và `mbstring`.
- Laragon hoặc môi trường web server PHP tương đương.

## Chạy với Laragon

1. Đặt thư mục dự án là `huit-schedule` trong `C:\laragon\www` và khởi động Apache/Nginx cùng MySQL.
2. Tạo file `.env` từ `.env.example`, sau đó điều chỉnh thông tin kết nối cho MySQL cục bộ.
3. Cài thư viện mã nguồn mở:

   ```sh
   composer install
   ```

4. Import `database/schema.sql` bằng HeidiSQL hoặc MySQL CLI. File SQL tự tạo database `huit_db`.
5. Sinh dữ liệu mẫu và tài khoản đăng nhập:

   ```sh
   php database/seed.php
   ```

6. Truy cập `http://huit-schedule.test`. Nếu Laragon chưa tự tạo virtual host, bật **Auto Virtual Hosts** và thêm thư mục dự án vào `C:\laragon\www`.

### Cấu hình `.env`

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://huit-schedule.test

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=huit_db
DB_USER=root
DB_PASSWORD=
```

Không đưa `.env` hoặc mật khẩu thật lên Git. `.env.example` chỉ chứa giá trị mẫu.

## Tài khoản demo

| Vai trò | Email | Mật khẩu |
|---|---|---|
| Quản trị viên | `admin@huit.local` | `Admin123!` |
| Giảng viên | `an.nguyen@huit.local` | `Teacher123!` |

Lệnh seed tạo thêm bảy tài khoản giảng viên (`gv04@huit.local` đến `gv10@huit.local`), mật khẩu ban đầu là `Teacher123!`. Đây là tài khoản phục vụ thực hành; hãy đổi mật khẩu trước khi triển khai thật.

## Quy tắc phân lịch

- Một tiết học được xem là 50 phút; khoảng thời gian di chuyển cần thiết được làm tròn lên số tiết trống.
- Thời gian đi lại giữa các cơ sở mẫu được lưu trong `branch_travel_times` và có thể chỉnh sửa theo thực tế.
- Ứng dụng không cho lưu lịch trùng tiết cho cùng giảng viên hoặc cùng phòng; đồng thời từ chối lịch không đủ thời gian chuyển cơ sở.
- Gợi ý giảng viên xếp theo mức độ khớp chuyên môn, số lần chuyển cơ sở dự kiến, tổng số tiết trống giữa các lớp và số lớp đã được phân trong ngày. Từ khóa chuyên môn được so khớp theo tên học phần.

## GitHub Actions

Workflow `.github/workflows/php-lint.yml` chạy `php -l` trên mọi tệp PHP khi push hoặc tạo pull request.

## Phụ thuộc

- [vlucas/phpdotenv](https://packagist.org/packages/vlucas/phpdotenv) — đọc cấu hình môi trường.
- [FakerPHP/Faker](https://packagist.org/packages/fakerphp/faker) — sinh dữ liệu mẫu giảng viên.

## License

Dự án được phát hành theo giấy phép [MIT](LICENSE).
