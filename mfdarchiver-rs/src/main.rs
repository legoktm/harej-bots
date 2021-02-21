/** mfdarchiver-rs -- Moves MfD discussions to the archive.
 *
 *  (c) 2013 Chris Grant - http://en.wikipedia.org/wiki/User:Chris_G
 *  (c) 2021 Kunal Mehta <legoktm@member.fsf.org>
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
use hblib::mediawiki::{
    page::{Page, PageError},
    title::Title,
};
use hblib::{mwapi_auth, print_diff, setup_logging};
use log::{debug, error, info};
use parsoid::prelude::*;
use regex::Regex;

const USER_AGENT: &str = "https://en.wikipedia.org/wiki/User:Legobot mfdarchiver-rs";

/// Extract all the currently transcluded MFDs
async fn get_listed_mfds(code: &Wikicode) -> Result<Vec<String>> {
    // Get all the MFDs transcluded on the page but not {{/Front matter}}
    let mfds = code
        .filter_templates()?
        .iter()
        .filter_map(|temp| {
            let name = temp.name();
            if name.starts_with("Wikipedia:Miscellany for deletion")
                && name != "Wikipedia:Miscellany for deletion/Front matter"
            {
                Some(name)
            } else {
                None
            }
        })
        // Reverse so we process oldest to newest
        .rev()
        .collect();
    Ok(mfds)
}

/// If the MfD is closed
fn is_closed(code: &Wikicode) -> bool {
    code.text_contents()
        .contains("The following discussion is an archived debate")
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

/// Archive MFDs older than 18 hours
fn should_archive(close_ts: &DateTime<Utc>) -> bool {
    // The close time plus 18 hours is less (older) than now
    (*close_ts + Duration::hours(18)) <= Utc::now()
}

/// Move to "Old business" if more than 8 days old
fn is_old(start_ts: &DateTime<Utc>) -> bool {
    // The start time plus 8 days is less (older) than now
    (*start_ts + Duration::days(8)) <= Utc::now()
}

/// Format a date into the header
fn make_header(date: &Date<Utc>) -> String {
    date.format("%B %-d, %Y").to_string()
}

/// Create a new archive page from scratch
async fn create_archive(client: &Client, ts: &DateTime<Utc>) -> Result<Wikicode> {
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

/// Find the result of the close
fn extract_result(code: &Wikicode) -> Option<String> {
    // It's the second bold thing
    let nodes = code.select("b");
    nodes.get(1).map(|node| node.text_contents())
}

/// Add the specified MfD to the archive page
fn add_to_archive(
    archive_code: &Wikicode,
    start_ts: &DateTime<Utc>,
    mfd: &str,
    result: &Option<String>,
) {
    let date = make_header(&start_ts.date());
    for section in archive_code.iter_sections() {
        if let Some(heading) = section.heading() {
            if heading.text_contents() == date {
                let line = Wikicode::new_node("li");
                line.append(&WikiLink::new(
                    mfd,
                    &Wikicode::new_text(mfd),
                ));
                if let Some(result) = &result {
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
                    },
                }
            }
        }
    }
}

/// Add a MfD to "Old business"
fn add_to_old_business(code: &Section, start_ts: &Date<Utc>, mfd: &str) -> Result<()> {
    let date = make_header(start_ts);
    // First check to make sure it's not already in the old business
    for temp in code.filter_templates()? {
        if temp.name() == mfd {
            return Ok(());
        }
    }
    let template = Template::new_simple(mfd);
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
            template.insert_after_on(&heading);
            return Ok(());
        }
    }
    // We did not find our date's header, boo.
    let heading = Heading::new(3, &Wikicode::new_text(&date))?;
    headings[0].insert_before(&heading);
    template.insert_before_on(&headings[0]);
    Ok(())
}

/// Remove a MfD from the current section
fn remove_from_current(code: &Section, mfd: &str) -> Result<()> {
    for temp in code.filter_templates()? {
        if temp.name() == mfd {
            temp.detach();
            break;
        }
    }
    Ok(())
}

/// Aside from the heading, is the section empty? Then remove it.
fn cleanup_empty_sections(code: &Section) {
    for section in code.iter_sections() {
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
    let client = Client::new("https://en.wikipedia.org/api/rest_v1", USER_AGENT)?;
    let mfd_code = client.get("Wikipedia:Miscellany for deletion").await?;
    let mut api = mwapi_auth(USER_AGENT).await?;
    let mfds = get_listed_mfds(&mfd_code).await?;
    for mfd in &mfds {
        debug!("Processing {}", mfd);
        let code = client.get(&mfd).await?;
        let text = code.text_contents();
        // Extract the timestamps out of this discussion
        let ts_re = Regex::new(r"\d\d:\d\d, \d?\d \w+ \d\d\d\d \(UTC\)").unwrap();
        let found: Vec<_> = ts_re.captures_iter(&text).collect();
        // TODO: will panic if we don't find the timestamps
        // FIXME: we should look through history instead of parsing timestamps
        // If open, first_timestamp is start_ts. If closed, first_timestamp is close_ts
        let first_timestamp = parse_timestamp(&found[0][0])?;
        if is_closed(&code) {
            info!("{} is closed.", mfd);
            let close_ts = first_timestamp;
            let start_ts = parse_timestamp(&found[1][0])?;
            debug!("close_ts = {}", close_ts);
            debug!("start_ts = {}", start_ts);
            let result = extract_result(&code);
            if should_archive(&close_ts) {
                info!("Going to archive {}", mfd);
                let archive = start_ts
                    .format("Wikipedia:Miscellany for deletion/Archived debates/%B %Y")
                    .to_string();
                let archive_code = match client.get(&archive).await {
                    Ok(code) => code,
                    // Doesn't exist yet, create a new one
                    Err(parsoid::Error::PageDoesNotExist(_)) => {
                        create_archive(&client, &start_ts).await?
                    }
                    Err(e) => return Err(anyhow!(e)),
                };
                // Add to archive page
                add_to_archive(&archive_code, &start_ts, &mfd, &result);
                let new_wikitext = client.transform_to_wikitext(&archive_code).await?;
                let page = Page::new(Title::new_from_full(&archive, &api));
                let original_wikitext = match page.text(&api).await {
                    Ok(text) => text,
                    Err(PageError::Missing(_)) => "".to_string(),
                    Err(e) => return Err(anyhow!(e.to_string())),
                };
                info!("Diff of [[{}]]:", &archive);
                if print_diff(&original_wikitext, &new_wikitext) {
                    page.edit_text(&mut api, &new_wikitext, format!("Archiving: [[{}]]", mfd))
                        .await
                        .map_err(|e| anyhow!(e.to_string()))?;
                    info!("Saved edit to [[{}]]", &archive);
                }
                // Remove from WP:MfD
                for temp in mfd_code.filter_templates()? {
                    if &temp.name() == mfd {
                        temp.detach();
                    }
                }
            }
        } else if is_old(&first_timestamp) {
            info!("Moving {} to Old business", mfd);
            let start_ts = first_timestamp;
            // Move under "Old business", optionally creating a heading if necessary
            for section in mfd_code.iter_sections() {
                if let Some(heading) = section.heading() {
                    if heading.text_contents() == "Old business" {
                        add_to_old_business(&section, &start_ts.date(), &mfd)?;
                        cleanup_empty_sections(&section);
                    } else if heading.text_contents() == "Current discussions" {
                        remove_from_current(&section, &mfd)?;
                        cleanup_empty_sections(&section);
                    }
                }
            }
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

    let mfd_wikitext = client.transform_to_wikitext(&mfd_code).await?;
    let mfd_page = Page::new(Title::new_from_full(
        "Wikipedia:Miscellany for deletion",
        &api,
    ));
    let mfd_original = mfd_page
        .text(&api)
        .await
        .map_err(|e| anyhow!(e.to_string()))?;

    info!("Diff of [[Wikipedia:Miscellany for deletion]]:");
    if print_diff(&mfd_original, &mfd_wikitext) {
        mfd_page
            .edit_text(&mut api, &mfd_wikitext, "Removing archived MfD debates")
            .await
            .map_err(|e| anyhow!(e.to_string()))?;
        info!("Saved edit to [[Wikipedia:Miscellany for deletion]]");
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
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
}
