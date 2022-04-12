/** mfdarchiver-rs -- Moves MfD discussions to the archive.
 *
 *  (c) 2013 Chris Grant - http://en.wikipedia.org/wiki/User:Chris_G
 *  (c) 2021 Kunal Mehta <legoktm@debian.org>
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.
 *
 *  Developers (add yourself here if you worked on the code):
 *    Kunal Mehta - [[User:Legoktm]] - Rewrote the bot, again.
 *    Chris Grant - [[User:Chris G]] - Rewrote the bot.
 *    James Hare  - [[User:Harej]]   - Wrote the original bot.
 **/
use anyhow::{anyhow, Result};
use chrono::prelude::*;
use chrono::Duration;
use hblib::{print_diff, setup_logging};
use log::{debug, error, info};
use mwbot::{Bot, Error, Page, SaveOptions};
use parsoid::prelude::*;
use regex::Regex;
use std::collections::HashMap;

/// Extract all the currently transcluded MFDs
fn get_listed_mfds(code: &Wikicode) -> Result<Vec<String>> {
    // Get all the MFDs transcluded on the page but not {{/Front matter}}
    let mfds = code
        .filter_templates()?
        .iter()
        .map(|temp| temp.name())
        .filter(|name| {
            name.starts_with("Wikipedia:Miscellany for deletion")
                && name != "Wikipedia:Miscellany for deletion/Front matter"
        })
        // Reverse so we process oldest to newest
        .rev()
        .collect();
    Ok(mfds)
}

struct MfD {
    page: Page,
    code: Wikicode,
    start: DateTime<Utc>,
    close: Option<DateTime<Utc>>,
}

impl MfD {
    /// If the MfD is closed
    fn is_closed(&self) -> bool {
        self.code
            .text_contents()
            .contains("The following discussion is an archived debate")
    }

    /// Move to "Old business" if more than 8 days old
    fn is_old(&self) -> bool {
        // The start time plus 8 days is less (older) than now
        (self.start + Duration::days(8)) <= Utc::now()
    }

    /// Archive MFDs older than 18 hours
    fn should_archive(&self) -> bool {
        match self.close {
            Some(end) => {
                // The close time plus 18 hours is less (older) than now
                (end + Duration::hours(18)) <= Utc::now()
            }
            None => false,
        }
    }

    /// Find the result of the close
    fn extract_result(&self) -> Option<String> {
        // It's the second bold thing
        let nodes = self.code.select("b");
        nodes.get(1).map(|node| node.text_contents())
    }

    fn as_link(&self) -> WikiLink {
        WikiLink::new(self.page.title(), &Wikicode::new_text(self.page.title()))
    }
}

/// Given a string from the regex, turn it into a DateTime
fn parse_timestamp(ts: &str) -> Result<DateTime<Utc>> {
    Ok(Utc.datetime_from_str(ts, "%H:%M, %-d %B %Y (UTC)")?)
}

// Inspired by https://stackoverflow.com/questions/53687045/how-to-get-the-number-of-days-in-a-month-in-rust
fn days_in_month(year: i32, month: u32) -> i64 {
    let next = if month == 12 {
        NaiveDate::from_ymd(year + 1, 1, 1)
    } else {
        NaiveDate::from_ymd(year, month + 1, 1)
    };
    next.signed_duration_since(NaiveDate::from_ymd(year, month, 1))
        .num_days()
}

/// Format a date into the header
fn make_header(date: &Date<Utc>) -> String {
    date.format("%B %-d, %Y").to_string()
}

/// Build a new archive page from scratch
async fn build_archive(client: &ParsoidClient, ts: &DateTime<Utc>) -> Result<Wikicode> {
    let code = Wikicode::new(&Template::new_simple("TOCright").to_string());
    let days = days_in_month(ts.year(), ts.month());
    for day in (1..=days).rev() {
        let date = Utc.ymd(ts.year(), ts.month(), day as u32);
        let heading = Heading::new(3, &Wikicode::new_text(&make_header(&date)))?;
        code.append(&heading);
    }
    // Roundtrip through Parsoid so we get nice <section> tags
    let wikitext = client.transform_to_wikitext(&code).await?;
    let code = client.transform_to_html(&wikitext).await?;
    Ok(code)
}

/// Add the specified MfD to the archive page
fn add_to_archive(archive_code: &Wikicode, mfd: &MfD) -> Result<()> {
    let date = make_header(&mfd.start.date());
    for section in archive_code.iter_sections() {
        if let Some(heading) = section.heading() {
            if heading.text_contents() == date {
                let line = Wikicode::new_node("li");
                line.append(&mfd.as_link());
                if let Some(result) = mfd.extract_result() {
                    line.append(&Wikicode::new_text(&format!(" ({})", result)));
                }
                match section.select_first("ul") {
                    // Add our bullet to the top of the list
                    Some(list) => {
                        list.prepend(&line);
                    }
                    // Wrap the line in a <ul> block
                    None => {
                        let ul = Wikicode::new_node("ul");
                        ul.append(&line);
                        section.append(&ul);
                    }
                }
                return Ok(());
            }
        }
    }
    Err(anyhow!(
        "Unable to add {} to the archive, opened at {}",
        mfd.page.title(),
        mfd.start
    ))
}

/// Add a MfD to "Old business"
fn add_to_old_business(code: &Section, mfd: &MfD) -> Result<()> {
    let date = make_header(&mfd.start.date());
    // First check to make sure it's not already in the old business
    for temp in code.filter_templates()? {
        if temp.name() == mfd.page.title() {
            return Ok(());
        }
    }
    let template = Template::new_simple(mfd.page.title());
    // We can't use iter_sections() here because our newly inserted
    // header won't have <section> tags yet
    let headings: Vec<_> = code
        .select("h3")
        .iter()
        .filter_map(|node| node.as_heading())
        .collect();
    for heading in &headings {
        if heading.text_contents() == date {
            // Add a newline to make the wikitext look nicer
            heading.insert_after(&Wikicode::new_text("\n"));
            heading.insert_after(&template);
            return Ok(());
        }
    }
    // We did not find our date's header, boo.
    let heading = Heading::new(3, &Wikicode::new_text(&date))?;
    if let Some(first_heading) = headings.get(0) {
        first_heading.insert_before(&heading);
        first_heading.insert_before(&template);
    } else {
        // There are no sub-headers at all, so append at the bottom of the section
        code.append(&heading);
        code.append(&template);
    }
    Ok(())
}

/// Remove a MfD from the current section
fn remove_from_current(code: &Section, mfd: &MfD) -> Result<()> {
    for temp in code.filter_templates()? {
        if temp.name() == mfd.page.title() {
            temp.detach();
            return Ok(());
        }
    }
    Err(anyhow!("Unable to find {} in current", mfd.page.title()))
}

/// Check whether an MfD is already in == Old business ==
fn is_in_old_business(code: &Wikicode, mfd: &MfD) -> Result<bool> {
    for section in code.iter_sections() {
        if let Some(heading) = section.heading() {
            if heading.text_contents() == "Old business" {
                for temp in section.filter_templates()? {
                    if temp.name() == mfd.page.title() {
                        return Ok(true);
                    }
                }
            }
        }
    }
    Ok(false)
}

/// Aside from the heading, is the section empty? Then remove it.
fn cleanup_empty_sections(code: &Section) {
    for section in code.iter_sections() {
        if let Some(heading) = section.heading() {
            // If today's header is empty don't try to remove it
            if heading.text_contents() == make_header(&Utc::today()) {
                continue;
            }
        }
        let mut children: Vec<_> = section
            .children()
            .map(|node| node.text_contents())
            .collect();
        // Remove the heading (hopefully it's first?)
        if !children.is_empty() {
            children.remove(0);
        }
        let thing: String = children.join("");
        if thing.trim().is_empty() {
            section.detach();
        }
    }
}

#[tokio::main]
async fn main() {
    let logger = setup_logging("mfdarchiver_rs");
    match run().await {
        Ok(_) => info!("Finished successfully"),
        Err(e) => error!("Error: {}", e.to_string()),
    };
    logger.shutdown();
}

async fn run() -> Result<()> {
    let bot = Bot::from_default_config().await?;
    let mfd_page = bot.get_page("Wikipedia:Miscellany for deletion");
    let mfd_code = mfd_page.get_html().await?;
    let mfds = get_listed_mfds(&mfd_code)?;
    let mut to_archive = vec![];
    for mfd in &mfds {
        info!("Processing {}", mfd);
        let page = bot.get_page(mfd);
        let code = page.get_html().await?;
        let text = code.text_contents();
        // Extract the timestamps out of this discussion
        let ts_re = Regex::new(r"\d\d:\d\d, \d?\d \w+ \d\d\d\d \(UTC\)").unwrap();
        let found: Vec<_> = ts_re.captures_iter(&text).collect();
        if found.len() < 2 {
            // Malformed, skip for now
            continue;
        }
        // TODO: will panic if we don't find the timestamps
        // FIXME: we should look through history instead of parsing timestamps
        // If open, first_timestamp is start_ts. If closed, first_timestamp is close_ts
        let first_timestamp = parse_timestamp(&found[0][0])?;
        let mut mfd = MfD {
            page,
            code,
            start: first_timestamp,
            close: None,
        };
        if mfd.is_closed() {
            info!("{} is closed.", mfd.page.title());
            // The first timestamp is actually the close message, and the
            // second timestamp is the real opening one
            mfd.close = Some(first_timestamp);
            mfd.start = parse_timestamp(&found[1][0])?;
            debug!("close_ts = {:?}", &mfd.close);
            debug!("start_ts = {:?}", &mfd.start);
            if mfd.should_archive() {
                info!("Going to archive {}", mfd.page.title());
                to_archive.push(mfd);
            } else if mfd.is_old() && !is_in_old_business(&mfd_code, &mfd)? {
                info!("Moving {} to Old business", mfd.page.title());
                // Move under "Old business", optionally creating a heading if necessary
                for section in mfd_code.iter_sections() {
                    if let Some(heading) = section.heading() {
                        if heading.text_contents() == "Old business" {
                            add_to_old_business(&section, &mfd)?;
                            cleanup_empty_sections(&section);
                        } else if heading.text_contents() == "Current discussions" {
                            remove_from_current(&section, &mfd)?;
                            cleanup_empty_sections(&section);
                        }
                    }
                }
            }
        }
    }

    let mut sorted_to_archive: HashMap<String, Vec<MfD>> = HashMap::new();
    for mfd in to_archive {
        let title = mfd
            .start
            .format("Wikipedia:Miscellany for deletion/Archived debates/%B %Y")
            .to_string();
        sorted_to_archive.entry(title).or_default().push(mfd);
    }

    for (archive_title, mfds) in sorted_to_archive.into_iter() {
        let archive = bot.get_page(&archive_title);
        let mut summary = vec![];
        let archive_code = match archive.get_html().await {
            Ok(code) => code,
            Err(Error::PageDoesNotExist(_)) => {
                build_archive(bot.get_parsoid(), &mfds[0].start).await?
            }
            Err(err) => return Err(err.into()),
        };
        for mfd in mfds {
            // Add to archive page
            add_to_archive(&archive_code, &mfd)?;
            // Remove from WP:MfD
            for temp in mfd_code.filter_templates()? {
                if temp.name() == mfd.page.title() {
                    temp.detach();
                }
            }
            summary.push(format!("[[{}]]", mfd.page.title()));
        }

        let new_wikitext = bot
            .get_parsoid()
            .transform_to_wikitext(&archive_code)
            .await?;
        let original_wikitext = match archive.get_wikitext().await {
            Ok(text) => text,
            Err(Error::PageDoesNotExist(_)) => "".to_string(),
            Err(err) => return Err(err.into()),
        };
        info!("Diff of [[{}]]:", archive.title());
        if print_diff(&original_wikitext, &new_wikitext) {
            archive
                .save(
                    archive_code,
                    &SaveOptions::summary(&format!("Archiving: {}", summary.join(", "))),
                )
                .await?;
            info!("Saved edit to [[{}]]", archive.title());
        }
    }

    // Finally, let's cleanup the empty sections we left behind
    for section in mfd_code.iter_sections() {
        if let Some(heading) = section.heading() {
            let text = heading.text_contents();
            if text == "Old business" || text == "Current discussions" {
                cleanup_empty_sections(&section);
            }
        }
    }

    let mfd_wikitext = bot.get_parsoid().transform_to_wikitext(&mfd_code).await?;
    let mfd_original = mfd_page.get_wikitext().await?;

    info!("Diff of [[Wikipedia:Miscellany for deletion]]:");
    if print_diff(&mfd_original, &mfd_wikitext) {
        mfd_page
            .save(
                mfd_code,
                &SaveOptions::summary(
                    "Removing archived MfD debates and/or moving to old business",
                ),
            )
            .await?;
        info!("Saved edit to [[Wikipedia:Miscellany for deletion]]");
    }

    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::path::Path;

    async fn bot() -> Bot {
        Bot::from_path(Path::new("mwbot-test.toml")).await.unwrap()
    }

    #[test]
    fn test_days_in_month() {
        // January
        assert_eq!(31, days_in_month(2021, 1));
        // February
        assert_eq!(28, days_in_month(2021, 2));
        // February (leap year)
        assert_eq!(29, days_in_month(2020, 2));
        // April
        assert_eq!(30, days_in_month(2021, 4));
    }

    #[tokio::test]
    async fn test_get_listed_mfds() {
        let bot = bot().await;
        let page = bot.get_page("Wikipedia:Miscellany for deletion");
        let code = page.get_revision_html(1017775433).await.unwrap();
        let mfds = get_listed_mfds(&code).unwrap();
        assert!(mfds.contains(
            &"Wikipedia:Miscellany for deletion/Draft:Why do we sometimes disagree about colors?"
                .to_string()
        ))
    }

    #[tokio::test]
    async fn test_mfd() {
        let bot = bot().await;
        let page = bot.get_page(
            "Wikipedia:Miscellany for deletion/Draft:Why do we sometimes disagree about colors?",
        );
        let code = page.get_revision_html(1016680706).await.unwrap();
        let mut mfd = MfD {
            page,
            code,
            start: Utc::now(),
            close: None,
        };
        assert!(!mfd.is_closed());
        assert!(!mfd.is_old());
        mfd.code = mfd.page.get_revision_html(1017953041).await.unwrap();
        assert!(mfd.is_closed());
        assert_eq!(mfd.extract_result(), Some("Delete".to_string()));
        // Open for 7 days, not yet old
        mfd.start = mfd.start - Duration::days(7);
        assert!(!mfd.is_old());
        // Open for 9 days, now old
        mfd.start = mfd.start - Duration::days(2);
        assert!(mfd.is_old());
        // No close time, shouldn't archive.
        assert!(!mfd.should_archive());
        mfd.close = Some(Utc::now());
        // Close time is now, shouldn't archive
        assert!(!mfd.should_archive());
        // Closed 19 hours ago, should archive
        mfd.close = Some(Utc::now() - Duration::hours(19));
        assert!(mfd.should_archive());
    }
}
