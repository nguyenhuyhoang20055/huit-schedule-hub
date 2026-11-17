document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('#schedule-form');
    if (!form) return;

    const subject = document.querySelector('#subject_name');
    const branch = document.querySelector('#branch_id');
    const day = document.querySelector('#day_of_week');
    const start = document.querySelector('#start_period');
    const end = document.querySelector('#end_period');
    const teacher = document.querySelector('#teacher_id');
    const suggestionBox = document.querySelector('#suggestion-box');
    let requestTimer;

    const loadSuggestions = async () => {
        const params = new URLSearchParams({
            page: 'suggestions',
            subject_name: subject.value,
            branch_id: branch.value,
            day_of_week: day.value,
            start_period: start.value,
            end_period: end.value,
            exclude_id: form.querySelector('[name="id"]').value
        });
        suggestionBox.textContent = 'Đang kiểm tra lịch trống và chuyên môn...';

        try {
            const response = await fetch(`?${params}`, {
                headers: { Accept: 'application/json' }
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Không thể tải gợi ý.');

            suggestionBox.replaceChildren();
            if (result.teachers.length === 0) {
                suggestionBox.textContent = 'Không tìm thấy giảng viên phù hợp và còn trống trong khung giờ này.';
                return;
            }

            const title = document.createElement('strong');
            title.textContent = 'Giảng viên phù hợp đang trống';
            const list = document.createElement('div');
            list.className = 'suggestion-list';
            result.teachers.slice(0, 5).forEach((candidate) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'suggestion-chip';
                button.textContent = `${candidate.full_name} · ${candidate.expertise} · ${candidate.campus_changes} lần chuyển cơ sở / ${candidate.dead_gaps} tiết trống`;
                button.addEventListener('click', () => {
                    teacher.value = candidate.id;
                    teacher.focus();
                });
                list.append(button);
            });
            suggestionBox.append(title, list);
        } catch (error) {
            suggestionBox.textContent = error.message || 'Không thể tải gợi ý giảng viên.';
        }
    };

    const scheduleSuggestions = () => {
        window.clearTimeout(requestTimer);
        requestTimer = window.setTimeout(loadSuggestions, 250);
    };
    [subject, branch, day, start, end].forEach((field) => {
        field.addEventListener('change', scheduleSuggestions);
        field.addEventListener('input', scheduleSuggestions);
    });
    scheduleSuggestions();
});
