use flexi_logger::LoggerHandle;
use log::info;

/// Return whether changes were made
pub fn print_diff(old: &str, new: &str) -> bool {
    use similar::TextDiff;
    let diff = TextDiff::from_lines(old, new).unified_diff().to_string();
    for line in diff.split('\n') {
        info!("{}", line);
    }
    !diff.trim().is_empty()
}

pub fn setup_logging(name: &str) -> LoggerHandle {
    use flexi_logger::{opt_format, Cleanup, Criterion, Duplicate, Logger, Naming};
    Logger::with_str(format!("info, {}=debug", name))
        .log_to_file()
        .duplicate_to_stdout(Duplicate::Info)
        .format(opt_format)
        .append()
        .use_buffering(true)
        .rotate(
            Criterion::Size(5_000_000),
            Naming::Timestamps,
            Cleanup::KeepLogFiles(30),
        )
        .start()
        .unwrap()
}
