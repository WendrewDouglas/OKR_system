class ApiResponse<T> {
  final bool ok;
  final T? data;
  final String? error;
  final String? message;

  ApiResponse({required this.ok, this.data, this.error, this.message});

  factory ApiResponse.fromJson(Map<String, dynamic> json, T Function(Map<String, dynamic>)? fromJson) {
    return ApiResponse(
      ok: json['ok'] == true,
      data: json['ok'] == true && fromJson != null ? fromJson(json) : null,
      error: json['error'] as String?,
      message: json['message'] as String?,
    );
  }

  factory ApiResponse.success(T data) => ApiResponse(ok: true, data: data);
  factory ApiResponse.failure(String message, [String? code]) =>
      ApiResponse(ok: false, message: message, error: code);
}

class PaginatedResponse<T> {
  final List<T> items;
  final int page;
  final int perPage;
  final int total;
  final int pages;

  PaginatedResponse({
    required this.items,
    required this.page,
    required this.perPage,
    required this.total,
    required this.pages,
  });
}
