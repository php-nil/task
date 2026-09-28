<?php

namespace NilTask\Plan;

/**
 * 计划周期
 *
 * JSON 结构（每个键均可省略，值支持数字或数字数组）：
 * - week      ：每周第几天（ISO-8601，1=周一 ... 7=周日），如 {"week":1}
 *               数组表示多个星期，如 {"week":[1,3,5]}
 * - day       ：每隔几天执行一次（正整数，以固定基准日取模），如 {"day":2}
 * - month     ：每月第几天（1-31），如 {"month":15}，31 仅在存在 31 天的月份生效
 * - month_end ：当月倒数第几天（1=当月最后一天，1-31），如 {"month_end":1}
 *               各月天数不同，用于表达「每月最后一天」等；可数组，如 {"month_end":[1,2]}
 * - day_offset：[间隔, 偏移]，每隔 N 天偏移 M 天（间隔正整数，0 ≤ 偏移 < 间隔）
 *               如 {"day_offset":[3,1]}；多组用数组 {"day_offset":[[3,0],[3,1]]}
 *               {"day_offset":[3,0]} 等价于 {"day":3}
 *
 * 多个维度之间为「或」关系：任一维度命中即创建当日任务；
 * 周期为空（{} 或未设置）时每天都执行。
 */
final class Cycle
{
    /**
     * day（间隔天数）取模计算所用的固定基准日
     * 保证「每隔 N 天」与计划创建时间无关、结果确定可预测
     */
    public const string INTERVAL_BASE_DATE = '1970-01-01';

    /**
     * 计算日期序号：距固定基准日的天数
     *
     * 用作任务的 doid：同一日期返回相同值、相邻日期恰差 1，
     * 使每天的当日任务为独立记录、互不阻塞，同日重复创建仍可去重。
     *
     * @param \DateTimeInterface $date 目标日期（取日期部分）
     */
    public static function dayOrdinal(\DateTimeInterface $date): int
    {
        $timezone = $date->getTimezone();
        $base = \DateTimeImmutable::createFromFormat('Y-m-d', self::INTERVAL_BASE_DATE, $timezone);
        $day = \DateTimeImmutable::createFromFormat('Y-m-d', $date->format('Y-m-d'), $timezone);

        return (int) $base->diff($day)->format('%a');
    }

    /**
     * @param int[]   $week      命中的星期（1-7）
     * @param int[]   $day       间隔天数
     * @param int[]   $month     命中的月内日期（1-31）
     * @param int[]   $monthEnd  命中的月末倒数日期（1-31）
     * @param int[][] $dayOffset [间隔, 偏移] 对列表
     */
    private function __construct(
        public readonly array $week,
        public readonly array $day,
        public readonly array $month,
        public readonly array $monthEnd,
        public readonly array $dayOffset
    ) {
    }

    /**
     * 从数组构造周期
     *
     * @param array<string,mixed> $cycle
     * @throws \InvalidArgumentException 当任一数值超出允许范围时抛出
     */
    public static function fromArray(array $cycle): self
    {
        return new self(
            self::normalize($cycle['week'] ?? null, 1, 7),
            self::normalize($cycle['day'] ?? null, 1, null),
            self::normalize($cycle['month'] ?? null, 1, 31),
            self::normalize($cycle['month_end'] ?? null, 1, 31),
            self::normalizeDayOffset($cycle['day_offset'] ?? null)
        );
    }

    /**
     * 归一化单个维度：标量转数组、去重并校验范围
     *
     * @param mixed $value 原始值
     * @param int $min 最小值
     * @param int|null $max 最大值（null 表示不设上限）
     * @return int[]
     */
    private static function normalize(mixed $value, int $min, ?int $max): array
    {
        if (null === $value || [] === $value || '' === $value) {
            return [];
        }

        if (!is_array($value)) {
            $value = [$value];
        }

        $list = [];

        foreach ($value as $item) {
            $number = (int) $item;

            if ($number < $min || (null !== $max && $number > $max)) {
                $range = null === $max ? (string) $min . '+' : $min . '-' . $max;

                throw new \InvalidArgumentException(\sprintf(
                    'cycle value %s is out of range (%s)',
                    (string) $item,
                    $range
                ));
            }

            $list[$number] = $number;
        }

        return array_values($list);
    }

    /**
     * 归一化 day_offset：接受单个 [间隔, 偏移] 或多组对
     *
     * @param mixed $value 原始值
     * @return int[][] 去重后的 [间隔, 偏移] 对列表
     * @throws \InvalidArgumentException 当格式或取值非法时抛出
     */
    private static function normalizeDayOffset(mixed $value): array
    {
        if (null === $value || [] === $value || '' === $value) {
            return [];
        }

        if (!is_array($value)) {
            throw new \InvalidArgumentException('day_offset must be [interval, offset] pair(s)');
        }

        // 恰好 2 个非数组元素视为单个对：[3,1]
        if (2 === count($value) && !is_array($value[0] ?? null) && !is_array($value[1] ?? null)) {
            $value = [$value];
        }

        $list = [];

        foreach ($value as $pair) {
            if (!is_array($pair) || 2 !== count($pair)) {
                throw new \InvalidArgumentException('day_offset pair must be [interval, offset]');
            }

            $interval = (int) $pair[0];
            $offset = (int) $pair[1];

            if ($interval < 1) {
                throw new \InvalidArgumentException('day_offset interval must be a positive integer');
            }

            if ($offset < 0 || $offset >= $interval) {
                throw new \InvalidArgumentException(\sprintf(
                    'day_offset offset must satisfy 0 <= offset < interval (got interval=%d, offset=%d)',
                    $interval,
                    $offset
                ));
            }

            $list[$interval . ':' . $offset] = [$interval, $offset];
        }

        return array_values($list);
    }

    /**
     * 转回可 JSON 序列化的数组（自动剔除空维度）
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $data = [
            'week' => $this->week,
            'day' => $this->day,
            'month' => $this->month,
            'month_end' => $this->monthEnd,
            'day_offset' => $this->dayOffset
        ];

        return array_filter($data, fn(array $v) => [] !== $v);
    }

    /**
     * 是否为空周期（空周期每天执行）
     */
    public function isEmpty(): bool
    {
        return [] === $this->week
            && [] === $this->day
            && [] === $this->month
            && [] === $this->monthEnd
            && [] === $this->dayOffset;
    }

    /**
     * 判断给定日期是否命中周期
     *
     * @param \DateTimeInterface $date 目标日期
     */
    public function matches(\DateTimeInterface $date): bool
    {
        if ($this->isEmpty()) {
            return true;
        }

        // 每周第几天（N：1=周一 ... 7=周日）
        $weekday = (int) $date->format('N');

        foreach ($this->week as $week) {
            if ($week === $weekday) {
                return true;
            }
        }

        // 每月第几天 / 当月倒数第几天
        $dayOfMonth = (int) $date->format('j');
        $daysInMonth = (int) $date->format('t');

        foreach ($this->month as $month) {
            if ($month === $dayOfMonth) {
                return true;
            }
        }

        foreach ($this->monthEnd as $fromEnd) {
            if ($dayOfMonth === $daysInMonth - $fromEnd + 1) {
                return true;
            }
        }

        // 每隔几天 / 带偏移的间隔：距基准日天数对间隔取模
        if ([] !== $this->day || [] !== $this->dayOffset) {
            $days = self::dayOrdinal($date);

            foreach ($this->day as $interval) {
                if (0 === $days % $interval) {
                    return true;
                }
            }

            foreach ($this->dayOffset as [$interval, $offset]) {
                if (0 === (($days - $offset) % $interval)) {
                    return true;
                }
            }
        }

        return false;
    }
}
