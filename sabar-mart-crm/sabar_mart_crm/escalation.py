"""Task escalation: turns rule breaches into routed internal tasks."""

from __future__ import annotations

from datetime import datetime
from typing import Any

from .models import InternalTask, Priority, TaskStatus
from .store import Store


class EscalationEngine:
    """Creates internal tasks, de-duplicating open tasks per (rule, subject)."""

    def __init__(self, store: Store) -> None:
        self.store = store

    @property
    def tasks(self):
        return self.store.tasks

    def raise_task(
        self,
        *,
        rule: str,
        title: str,
        owner_team: str,
        priority: Priority,
        subject_type: str,
        subject_id: str,
        now: datetime,
        details: dict[str, Any] | None = None,
    ) -> InternalTask:
        existing = self.open_task_for(rule, subject_id)
        if existing:
            existing.title = title
            existing.details.update(details or {})
            return existing
        task = InternalTask(
            task_id=f"TSK-{self.store.next_id('task'):05d}",
            rule=rule,
            title=title,
            owner_team=owner_team,
            priority=priority,
            subject_type=subject_type,
            subject_id=subject_id,
            created_at=now,
            details=dict(details or {}),
        )
        self.tasks[task.task_id] = task
        return task

    def open_task_for(self, rule: str, subject_id: str) -> InternalTask | None:
        for task in self.store.find("tasks", subject_id=subject_id):
            if task.rule == rule and task.status != TaskStatus.RESOLVED:
                return task
        return None

    def resolve(self, task_id: str) -> InternalTask:
        task = self.tasks[task_id]
        task.status = TaskStatus.RESOLVED
        return task

    def queue_for(self, team: str) -> list[InternalTask]:
        order = {Priority.CRITICAL: 0, Priority.HIGH: 1, Priority.MEDIUM: 2, Priority.LOW: 3}
        return sorted(
            (t for t in self.store.find("tasks", owner_team=team) if t.status != TaskStatus.RESOLVED),
            key=lambda t: (order[t.priority], t.created_at),
        )
